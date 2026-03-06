<?php
/**
 * Helper para validación de datos
 */

class Validator
{
	public static function required($value)
	{
		return !(is_null($value) || trim((string) $value) === '');
	}

	public static function parseAmount($value)
	{
		$text = trim((string) $value);
		if ($text === '') {
			return 0.0;
		}

		$text = str_replace(['$', ' '], '', $text);

		if (strpos($text, ',') !== false && strpos($text, '.') !== false) {
			$text = str_replace(',', '', $text);
		} elseif (strpos($text, ',') !== false) {
			$text = str_replace(',', '.', $text);
		}

		return (float) $text;
	}

	public static function parseDate($value)
	{
		$text = trim((string) $value);
		if ($text === '') {
			return null;
		}

		// Excel serial date (Windows epoch, with leap-year compatibility behavior).
		if (is_numeric($text)) {
			$serial = (float) $text;
			if ($serial > 0) {
				$days = (int) floor($serial);
				$seconds = (int) round(($serial - $days) * 86400);
				$base = new DateTime('1899-12-30 00:00:00');
				$base->modify('+' . $days . ' days');
				$base->modify('+' . $seconds . ' seconds');
				return $base->format('Y-m-d');
			}
		}

		$formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
		foreach ($formats as $format) {
			$date = DateTime::createFromFormat($format, $text);
			if ($date && $date->format($format) === $text) {
				return $date->format('Y-m-d');
			}
		}

		try {
			return (new DateTime($text))->format('Y-m-d');
		} catch (Exception $e) {
			return null;
		}
	}
}
