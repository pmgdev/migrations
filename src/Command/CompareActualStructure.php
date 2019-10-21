<?php declare(strict_types=1);

namespace PmgDev\Migrations\Command;

use Nette\Utils;
use PmgDev\Migrations\Config;
use PmgDev\Migrations\Exceptions\NotMatchingSchemaException;
use PmgDev\Migrations\Exceptions\ReadWriteException;
use PmgDev\Migrations\Exceptions\ScriptFailException;
use PmgDev\Migrations\Factory;
use Symfony\Component\Console\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;

final class CompareActualStructure extends Command\Command
{
	private const ACTUAL = 'actual.sql';
	private const STRUCTURE = 'structure.sql';

	/** @var string */
	private $tempDir;


	public function __construct(?string $tempDir = NULL)
	{
		$tempDir = rtrim($tempDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
		$this->tempDir = $tempDir . DIRECTORY_SEPARATOR . 'pmg-migrations-csa';
		parent::__construct('compare-structure:actual');
	}


	protected function configure(): void
	{
		$this->setDescription('Compare actual DB structure with structure file')
			->addOption('config', NULL, Input\InputOption::VALUE_REQUIRED);
	}


	public function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		/** @var string $configOption */
		$configOption = $input->getOption('config');
		$factory = new Factory(new Config($configOption));
		['updateScript' => $updateScript, 'credentials' => ['test' => $testDb]] = $factory->getParameters();

		Utils\FileSystem::createDir($this->tempDir);

		$actualFile = $this->tempDir . DIRECTORY_SEPARATOR . self::ACTUAL;
		$ourFile = $this->tempDir . DIRECTORY_SEPARATOR . self::STRUCTURE;

		$output->writeln(sprintf('Starting dump %s...', self::ACTUAL));
		$factory->createPsql()->dumpDatabase($actualFile);

		$testDbName = $this->updateStructure($updateScript);
		$output->writeln(sprintf('Starting dump %s...', self::STRUCTURE));
		$factory->createPsql([
			'host' => $testDb['host'],
			'dbname' => $testDbName,
			'port' => $testDb['port'],
		])->dumpDatabase($ourFile);

		$this->checkDiff([$actualFile, $ourFile]);
		$output->writeln('OK - structure.sql is the same as the database structure');

		return 0;
	}


	private function checkDiff(array $files): void
	{
		$md5 = [];
		foreach ($files as $file) {
			$originalContent = @file_get_contents($file);
			if ($originalContent === FALSE) {
				throw new ReadWriteException(sprintf('Can not read file %s', $file));
			} else if (@file_put_contents($file, trim(str_replace("\r\n", "\n", (string) preg_replace('~--.*~', '', $originalContent)))) === FALSE) {
				throw new ReadWriteException(sprintf('Can not write to file %s', $file));
			}
			$md5[] = md5_file($file);
		}

		if (count(array_unique($md5)) !== 1) {
			throw new NotMatchingSchemaException(sprintf('ERROR - %s and database structure are different, run "diff %s" and update database structure...', self::STRUCTURE, implode(' ', $files)));
		}
	}


	private function updateStructure(string $updateScript): string
	{
		exec(sprintf('php %s', $updateScript), $output, $return);
		$output = implode('', $output);
		if ($return > 0) {
			throw new ScriptFailException(sprintf('Failed update testing structure with code: %d. Is "tests" directory presents? Output was: %s', $return, $output));
		} else if (preg_match('~(_csfd_test_[a-z0-9]+)~', $output, $matches) !== 1) {
			throw new ScriptFailException(sprintf('Can not detect DB name from output: %s', $output));
		}
		return $matches[0];
	}

}
