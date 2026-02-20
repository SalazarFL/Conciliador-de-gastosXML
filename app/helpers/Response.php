<?php
/**
 * Helper para respuestas HTTP/JSON
 */

class Response
{
	public static function json($payload, $statusCode = 200)
	{
		http_response_code($statusCode);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		exit;
	}

	public static function success($data = [], $message = 'OK', $statusCode = 200)
	{
		self::json([
			'success' => true,
			'message' => $message,
			'data' => $data
		], $statusCode);
	}

	public static function error($message, $statusCode = 400, $errors = [])
	{
		self::json([
			'success' => false,
			'message' => $message,
			'errors' => $errors
		], $statusCode);
	}
}
