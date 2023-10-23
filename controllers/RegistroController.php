<?php

namespace Controllers;

use Model\Dia;
use Model\Hora;
use MVC\Router;
use Model\Evento;
use Model\Paquete;
use Model\Ponente;
use Model\Usuario;
use Model\Registro;
use Model\Categoria;
use Model\EventosRegistros;
use Model\Regalo;

class RegistroController {
    public static function crear(Router $router){

        if(!is_auth()){
            header('location: /');
            return;
        }

        //vewrificar si el usuario ya esta registrado
        $registro = Registro::where('usuario_id', $_SESSION['id']);

        if(isset($registro) && ($registro->paquete_id === "3" || $registro->paquete_id === "2")){
            header('location: /boleto?id=' . urlencode($registro->token));
            return;
        }

        if(isset($registro) && $registro->paquete_id === "1"){
            header('Location: /finalizar-registro/conferencias');
            return;
        }

        $router->render('registro/crear', [
            'titulo' => 'Finalizar Registro',
        ]);
    }

    public static function gratis(Router $router){

        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            if(!is_auth()){
                header('location: /login');
                return;
            }

             //vewrificar si el usuario ya esta registrado
            $registro = Registro::where('usuario_id', $_SESSION['id']);

            if(isset($registro) && $registro->paquete_id === "3"){
                header('location: /boleto?id=' . urlencode($registro->token));
                return;
            }

            $token = substr( md5( uniqid( rand(), true)), 0, 8);

            //crear registro
            $datos = [
                'paquete_id' => 3,
                'pago_id' => '',
                'token' => $token,
                'usuario_id' => $_SESSION['id']
            ];

            $registro = new Registro($datos);
            $resultado = $registro->guardar();

            if($resultado){
                header('location: /boleto?id=' . urlencode($registro->token));
                return;
            }          

        }
    }

    public static function boleto(Router $router){

        //validar la url
        $id = $_GET['id'];

        if(!$id || !strlen($id) === 8){
            header('location: /');
            return;
        }

        //buscar en la base de datos
        $registro = Registro::where('token' ,$id);
        if(!$registro){
            header('location: /');
            return;
        }

        //lenar las tablas de refeencia
        $registro->usuario = Usuario::find($registro->usuario_id);
        $registro->paquete = Paquete::find($registro->paquete_id);

        $router->render('registro/boleto', [
            'titulo' => 'Asistencia a DevWebCamp',
            'registro' => $registro
        ]);
    }

    public static function pagar (Router $router){

        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            if(!is_auth()){
                header('location: /login');
                return;
            }

            //validar que el post no venga vacio
            if(empty($_POST)){
                json_encode([]);
                return;
            }

            //crear el registro
            $datos = $_POST;
            $datos['token'] = substr( md5( uniqid( rand(), true)), 0, 8);
            $datos['usuario_id'] = $_SESSION['id'];
            
            try {
                $registro = new Registro($datos);
                $resultado = $registro->guardar();
                echo json_encode($resultado);
            } catch (\Throwable $th) {
                echo json_encode([
                    'resultado' => 'error'
                ]);
            }

        }
    }

    public static function conferencias(Router $router){

        if(!is_auth()) {
            header('Location: /login');
            return;
        }        
     
        // Validar que el usuario tenga el plan presencial
        $usuario_id = $_SESSION['id'];
        $registro = Registro::where('usuario_id', $usuario_id);
     
        // Aqui validas si el registro se ha completado o no
        $registroFinalizado = EventosRegistros::where('registro_id', $registro->id);
     
        if(isset($registro) && $registro->paquete_id === "2") {
            header('Location: /boleto?id=' . urlencode($registro->token));
            return;
        } 
     
        // Aqui validas si el registro se ha completado o no
        if(isset($registroFinalizado)) {
            header('Location: /boleto?id=' . urlencode($registro->token));
            return;
        }
     
        if($registro->paquete_id !== "1") {
            header('Location: /');
            return;
        }
     
        // Esta validación ya no es necesaria: 
        // Redireccionar a boleto virtual en caso de haber finalizado su registro
        // if(isset($registro->regalo_id) && $registro->paquete_id === "1") {
        //     header('Location: /boleto?id=' . urlencode($registro->token));
        //     return;
        // }
     
        $eventos = Evento::ordenar('hora_id', 'ASC');
        $eventos_formatiados = [];
        foreach($eventos as $evento){
            
            $evento->categoria = Categoria::find($evento->categoria_id);
            $evento->dia = Dia::find($evento->dia_id);
            $evento->hora = Hora::find($evento->hora_id);
            $evento->ponente = Ponente::find($evento->ponente_id);

            if($evento->dia_id === "1" && $evento->categoria_id === "1"){
                $eventos_formatiados['conferencias_v'][] = $evento;
            }
            if($evento->dia_id === "2" && $evento->categoria_id === "1"){
                $eventos_formatiados['conferencias_s'][] = $evento;
            }
            if($evento->dia_id === "1" && $evento->categoria_id === "2"){
                $eventos_formatiados['workshops_v'][] = $evento;
            }
            if($evento->dia_id === "2" && $evento->categoria_id === "2"){
                $eventos_formatiados['workshops_s'][] = $evento;
            }
        }

        $regalos  = Regalo::all('ASC');

        //m,anejar el registro mediante POST 
        if($_SERVER['REQUEST_METHOD'] === 'POST') {
            //revisar que el usuario este autenticado
            if(!is_auth()){
                header('location: /login');
                return;
            }

            $eventos = explode(',', $_POST['eventos']);
            if(empty($eventos)){
                echo json_encode(['resultado' => false]);
                return;
            }

            //obtener el registro del usuario
            $registro = Registro::where('usuario_id', $_SESSION['id']);
            if(!isset($registro) || $registro->paquete_id !== "1"){
                echo json_encode(['resultado' => false]);
                return;
            }

            $eventos_array = [];
            //validar la disponibilidad
            foreach($eventos as $evento_id){
                $evento = Evento::find($evento_id);
                //comprobar que el evento exista
                if(!isset($evento) || $evento->disponible === "0"){
                    echo json_encode(['resultado' => false]);
                    return;
                }
                $eventos_array[] = $evento;
            }

            foreach($eventos_array as $evento){
                $evento->disponibles -= 1;
                $evento->guardar();

                //almacenar el registro
                $datos = [
                    'evento_id' => (int) $evento->id,
                    'registro_id' => (int) $registro->id
                ];

                $registro_usuario = new EventosRegistros($datos);
                $registro_usuario->guardar();                
            }

            //almacenr el regalo
            $registro->sincronizar(['regalo_id' => $_POST['regalo_id']]);
            $resultado = $registro->guardar();

            if($resultado){
                echo json_encode([
                    'resultado' => $resultado,
                    'token' => $registro->token
                ]);
            }else{
                echo json_encode(['resultado' => false]);
            }

            return;
        }

        $router->render('registro/conferencias', [
            'titulo' => 'Elige Workshops y conferencias',
            'eventos' => $eventos_formatiados,
            'regalos' => $regalos
                
        ]);
    }

}