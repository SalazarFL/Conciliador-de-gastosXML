<?php
/**
 * Helper para gestión de carga de archivos
 */

class FileUploader
{
	public static function uploadMultiple($fieldName, $destinationDir, $allowedExtensions = [], $maxSize = 10485760)
	{
		if (!isset($_FILES[$fieldName])) {
			throw new Exception('No se encontró el campo de archivo en la solicitud.');
		}

		$files = self::normalizeFilesArray($_FILES[$fieldName]);
		$uploaded = [];

		self::ensureDirectory($destinationDir);

		foreach ($files as $file) {
			if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}

			self::validateFile($file, $allowedExtensions, $maxSize);

			$savedName = self::buildFileName($file['name']);
			$targetPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $savedName;

			if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
				throw new Exception('No fue posible mover el archivo subido: ' . $file['name']);
			}

			$uploaded[] = [
				'original_name' => $file['name'],
				'saved_name' => $savedName,
				'path' => $targetPath,
				'size' => $file['size']
			];
		}

		if (empty($uploaded)) {
			throw new Exception('No se subieron archivos válidos.');
		}

		return $uploaded;
	}

	public static function uploadSingle($fieldName, $destinationDir, $allowedExtensions = [], $maxSize = 10485760)
	{
		$uploaded = self::uploadMultiple($fieldName, $destinationDir, $allowedExtensions, $maxSize);
		return $uploaded[0];
	}

	private static function normalizeFilesArray($files)
	{
		if (!is_array($files['name'])) {
			return [$files];
		}

		$normalized = [];
		$total = count($files['name']);

		for ($index = 0; $index < $total; $index++) {
			$normalized[] = [
				'name' => $files['name'][$index],
				'type' => $files['type'][$index],
				'tmp_name' => $files['tmp_name'][$index],
				'error' => $files['error'][$index],
				'size' => $files['size'][$index]
			];
		}

		return $normalized;
	}

	private static function validateFile($file, $allowedExtensions, $maxSize)
	{
		if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
			throw new Exception('Error de carga en archivo: ' . ($file['name'] ?? 'desconocido'));
		}

		if (($file['size'] ?? 0) <= 0) {
			throw new Exception('El archivo está vacío: ' . ($file['name'] ?? 'desconocido'));
		}

		if (($file['size'] ?? 0) > $maxSize) {
			throw new Exception('El archivo excede el tamaño máximo permitido: ' . ($file['name'] ?? 'desconocido'));
		}

		if (!empty($allowedExtensions)) {
			$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
			if (!in_array($ext, $allowedExtensions, true)) {
				throw new Exception('Extensión no permitida para archivo: ' . ($file['name'] ?? 'desconocido'));
			}
		}
	}

	private static function buildFileName($originalName)
	{
		$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$base = pathinfo($originalName, PATHINFO_FILENAME);
		$base = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base);
		$base = trim($base, '_');
		$base = $base !== '' ? $base : 'archivo';

		return $base . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
	}

	private static function ensureDirectory($path)
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new Exception('No se pudo crear el directorio de destino: ' . $path);
		}
	}
}
