<?php declare(strict_types=1);

namespace PmgDev\Migrations;

use h4kuna\Memoize\MemoryStorage;
use Nette\Neon\Neon;

final class Config
{
	use MemoryStorage;

	/** @var string|NULL */
	private $filename;


	public function __construct(?string $filename)
	{
		$this->filename = $filename;
	}


	public function parameters(): array
	{
		return $this->memoize(__FUNCTION__, function (): array {
			if ($this->filename === NULL || $this->filename === '') {
				throw new \RuntimeException('Config must be specified');
			}
			$content = @file_get_contents($this->filename);
			if ($content === FALSE) {
				throw new \RuntimeException(sprintf('Config "%s" is not readable.', $this->filename));
			}
			return self::validate(Neon::decode($content));
		});
	}


	private static function validate(array $parameters): array
	{
		if (!is_dir($parameters['projectDir'])) {
			throw new \RuntimeException(sprintf('projectDir "%s" does not exist', $parameters['projectDir']));
		}
		if (!is_dir($parameters['migrationsDir'])) {
			throw new \RuntimeException(sprintf('migrationsDir "%s" does not exist', $parameters['migrationsDir']));
		}
		if (!is_file($parameters['sqlUtility'])) {
			throw new \RuntimeException(sprintf('sqlUtility "%s" does not exist', $parameters['sqlUtility']));
		}
		if (!is_file($parameters['dumpUtility'])) {
			throw new \RuntimeException(sprintf('dumpUtility "%s" does not exist', $parameters['dumpUtility']));
		}
		if (!is_file($parameters['structureFilename'])) {
			throw new \RuntimeException(sprintf('structureFilename "%s" does not exist', $parameters['structureFilename']));
		}
		return $parameters;
	}

}
