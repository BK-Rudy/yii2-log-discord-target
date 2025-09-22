<?php namespace thtmorais\log;

use Exception;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\LogRuntimeException;
use yii\log\Target;

/**
 * Class DiscordTarget
 * @package thtmorais\log
 * @property-write string $url
 */
class DiscordTarget extends Target
{
	/**
	 * Message pattern, for details @see \yii\i18n\I18N::format()
	 * Available parameters: {text}, {level}, {category}, {timestamp}
	 * @var string
	 */
	public $pattern = "[{level}]: \"{category}\"\n{text}";

	/** {@inheritDoc} */
	public $logVars = [];

	/** @var string */
	private $url;

	/**
	 * @param string $url
	 */
	public function setUrl($url)
	{
		$this->url = $url;
	}

	/**
	 * {@inheritDoc}
	 * @throws InvalidConfigException
	 */
	public function init()
	{
		parent::init();
		if (empty($this->url)) {
			throw new InvalidConfigException('$url attribute required');
		}
	}

	/**
	 * {@inheritDoc}
	 * @throws LogRuntimeException
	 */
	public function export()
	{
		$resultMessage = [];
		$prefix = Yii::$app->configuration->baseName;
		
		foreach ($this->messages as $message)
		{
			list($text, $level, $category, $timestamp) = $message;

			if (!is_string($text)) {
				// exceptions may not be serializable if in the call stack somewhere is a Closure
				if ($text instanceof Throwable || $text instanceof Exception) {
					$text = (string) $text;
				} else {
					$text = VarDumper::export($text);
				}
			}

			if ($prefix) {
                $text = "[$prefix] " . $text;
            }

			$resultMessage[] = Yii::$app->i18n->format(
				$this->pattern,
				[
					'text'      => $text,
					'level'     => Logger::getLevelName($level),
					'category'  => $category,
					'timestamp' => $this->getTime($timestamp),
				],
				Yii::$app->language
			);
		}

		if (count($resultMessage) > 0) {
			$this->sendWebhook(implode("\n", $resultMessage));
		}
	}

	/**
	 * @param string $message
	 * @throws LogRuntimeException
	 */
	private function sendWebHook($message)
	{

		// Create a file in the  temp directory and put the message in it
		$tempFilePath = sys_get_temp_dir() . '/' . uniqid("log") . '.txt';
		file_put_contents($tempFilePath, $message);
		
		if (function_exists('curl_file_create')) { // php 5.5+
			$curlFile = curl_file_create($tempFilePath);
		} else {
			$curlFile = '@' . realpath($tempFilePath);
		}
		
		$ch = curl_init($this->url);
		
		curl_setopt_array($ch, [
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_HTTPHEADER     => [
				'Content-type: multipart/form-data',
				'User-agent: Yii2 Discord log target',
			],
			CURLOPT_POSTFIELDS     => ['file1' => $curlFile],
			CURLOPT_RETURNTRANSFER => 1

		]);
		
		curl_exec($ch);
		$info = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		unlink($tempFilePath);		// delete the temp file

		if ($info !== 204) {
			throw new LogRuntimeException('Unable to export log through discord!');
		}
	}
}
