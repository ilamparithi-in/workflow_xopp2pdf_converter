<?php

declare(strict_types=1);

namespace OCA\WorkflowXoppToPdfConverter\BackgroundJobs;

use Exception;
use OC\Files\Filesystem;
use OC\Files\View;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class Convert extends QueuedJob {
	protected IConfig $config;
	protected ITempManager $tempManager;
	protected LoggerInterface $logger;
	private IRootFolder $rootFolder;

	public function __construct(
		IConfig $config,
		ITempManager $tempManager,
		LoggerInterface $logger,
		IRootFolder $rootFolder,
		ITimeFactory $timeFactory,
	) {
		parent::__construct($timeFactory);
		$this->config = $config;
		$this->tempManager = $tempManager;
		$this->logger = $logger;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @param mixed $argument
	 * @throws Exception
	 * @throws InvalidPathException
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	protected function run($argument) {
		$command = $this->getCommand();
		if ($command === null) {
			$this->logger->error('Can not find xournalpp path. Please make sure to configure "preview_xournalpp_path" in your config file.', [
				'app' => 'workflow_xopp2pdf_converter',
			]);
			return;
		}

		$path = (string)$argument['path'];
		$originalFileMode = (string)($argument['originalFileMode'] ?? 'keep');
		$targetPdfMode = (string)($argument['targetPdfMode'] ?? 'preserve');
		$outputDir = isset($argument['outputDir']) ? trim((string)$argument['outputDir']) : '';
		$namingPattern = !empty($argument['namingPattern']) ? trim((string)$argument['namingPattern']) : '{filename}.pdf';

		$pathSegments = explode('/', $path, 4);
		$user = $pathSegments[1];
		$dir = dirname($path);
		$file = basename($path);

		Filesystem::init($user, '/' . $user . '/files');
		try {
			$node = $this->rootFolder->get($path);
		} catch (NotFoundException $e) {
			return;
		}

		// Safeguard check: skip if .noconvert or .noexport was created
		if ($this->isExcludedByMarker($node)) {
			$this->logger->info('Skipping conversion for {file} due to marker file (.noconvert/.noexport)', [
				'app' => 'workflow_xopp2pdf_converter',
				'file' => $node->getPath(),
			]);
			return;
		}

		$view = new View($dir);
		$tmpPath = $view->toTmpFile($file);
		$tmpDir = $this->tempManager->getTempBaseDir();

		$newFileName = $this->generateFileName($file, $node, $user, $namingPattern);
		$newTmpPath = $tmpDir . '/' . uniqid('xopp_conv_', true) . '.pdf';

		try {
			$exec = escapeshellcmd($command)
				. ' --create-pdf=' . escapeshellarg($newTmpPath)
				. ' ' . escapeshellarg($tmpPath);

			$exitCode = 0;
			$out = [];
			exec($exec, $out, $exitCode);
			if ($exitCode !== 0) {
				$this->logger->error('could not convert {file}, reason: {out}', [
					'app' => 'workflow_xopp2pdf_converter',
					'file' => $node->getPath(),
					'out' => implode("\n", $out),
				]);
				return;
			}

			// Resolve destination folder
			$targetFolder = $this->resolveTargetFolder($node, $user, $outputDir);

			// Handle conflicts if target file already exists
			$targetBaseName = pathinfo($newFileName, PATHINFO_FILENAME);
			$index = 0;
			while ($targetPdfMode === 'preserve' && $targetFolder->nodeExists($newFileName)) {
				$index++;
				$newFileName = $targetBaseName . ' (' . $index . ').pdf';
			}

			// Write converted file back to Nextcloud
			$targetPath = '/' . ltrim($targetFolder->getPath(), '/');
			$targetView = new View($targetPath);
			$targetView->fromTmpFile($newTmpPath, $newFileName);

			// Delete original if requested
			if ($originalFileMode === 'delete') {
				try {
					$node->delete();
				} catch (\Exception $e) {
					$this->logger->warning('could not delete original file {file}: {msg}', [
						'app' => 'workflow_xopp2pdf_converter',
						'file' => $node->getPath(),
						'msg' => $e->getMessage(),
					]);
				}
			}
		} finally {
			// Clean up temporary files on disk
			if (file_exists($newTmpPath)) {
				@unlink($newTmpPath);
			}
			if (file_exists($tmpPath)) {
				@unlink($tmpPath);
			}
		}
	}

	protected function generateFileName(string $file, Node $node, string $user, string $pattern): string {
		$baseName = pathinfo($file, PATHINFO_FILENAME);
		$parent = $node->getParent();
		$parentName = $parent instanceof Folder ? $parent->getName() : '';

		$replacements = [
			'{filename}' => $baseName,
			'{date}' => date('Y-m-d'),
			'{datetime}' => date('Y-m-d_H-i-s'),
			'{year}' => date('Y'),
			'{month}' => date('m'),
			'{day}' => date('d'),
			'{parent}' => $parentName,
			'{user}' => $user,
		];

		$name = strtr($pattern, $replacements);
		// Sanitize separators and forbidden characters
		$name = str_replace(['/', '\\', "\0"], '_', $name);
		$name = trim($name);

		if ($name === '' || $name === '.pdf') {
			$name = $baseName . '.pdf';
		}

		if (!str_ends_with(strtolower($name), '.pdf')) {
			$name .= '.pdf';
		}

		return $name;
	}

	protected function resolveTargetFolder(Node $sourceNode, string $user, string $outputDir): Folder {
		$parent = $sourceNode->getParent();
		if (!($parent instanceof Folder)) {
			throw new UnexpectedValueException('Parent is not a folder');
		}

		if (empty($outputDir) || $outputDir === '.' || $outputDir === './') {
			return $parent;
		}

		$userFolder = $this->rootFolder->getUserFolder($user);
		$isAbsolute = str_starts_with($outputDir, '/');
		$trimmed = trim($outputDir, '/');
		$segments = array_filter(explode('/', $trimmed), fn($s) => $s !== '' && $s !== '.');

		$current = $isAbsolute ? $userFolder : $parent;

		foreach ($segments as $segment) {
			if ($segment === '..') {
				$parentDir = $current->getParent();
				if ($parentDir instanceof Folder && str_starts_with($parentDir->getPath(), $userFolder->getPath())) {
					$current = $parentDir;
				}
				continue;
			}

			if (!$current->nodeExists($segment)) {
				$current = $current->newFolder($segment);
			} else {
				$child = $current->get($segment);
				if ($child instanceof Folder) {
					$current = $child;
				} else {
					$this->logger->warning('Target directory segment {segment} is an existing file, falling back to parent folder', [
						'app' => 'workflow_xopp2pdf_converter',
						'segment' => $segment,
					]);
					return $parent;
				}
			}
		}

		return $current;
	}

	protected function isExcludedByMarker(Node $node): bool {
		try {
			$current = $node instanceof Folder ? $node : $node->getParent();
			$maxDepth = 20;
			while ($current instanceof Folder && $maxDepth-- > 0) {
				if ($current->nodeExists('.noconvert') || $current->nodeExists('.noexport')) {
					return true;
				}
				$path = trim($current->getPath(), '/');
				$parts = explode('/', $path);
				// Path looks like "username/files" or "files" at root level
				if (count($parts) <= 2 && in_array('files', $parts, true)) {
					break;
				}
				$parent = $current->getParent();
				if ($parent === null || $parent->getId() === $current->getId()) {
					break;
				}
				$current = $parent;
			}
		} catch (\Exception $e) {
		}
		return false;
	}

	protected function getCommand(): ?string {
		$xournalppPath = $this->config->getSystemValue('preview_xournalpp_path', null);
		if (is_string($xournalppPath)) {
			return escapeshellcmd($xournalppPath);
		}

		$whichXournalpp = shell_exec('command -v xournalpp');
		if (!empty($whichXournalpp)) {
			return 'xournalpp';
		}

		return null;
	}
}
