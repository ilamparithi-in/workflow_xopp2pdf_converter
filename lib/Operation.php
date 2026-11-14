<?php

declare(strict_types=1);

namespace OCA\WorkflowXoppToPdfConverter;

use OCA\WorkflowEngine\Entity\File;
use OCA\WorkflowXoppToPdfConverter\BackgroundJobs\Convert;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

class Operation implements ISpecificOperation {
	public const MODES = [
		'keep;preserve',
		'keep;overwrite',
		'delete;preserve',
		'delete;overwrite',
	];

	private IJobList $jobList;
	private IL10N $l;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		IJobList $jobList,
		IL10N $l,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
	) {
		$this->jobList = $jobList;
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	public function parseOperationConfig(string $operation): array {
		$decoded = json_decode($operation, true);
		if (is_array($decoded) && isset($decoded['mode'])) {
			return [
				'mode' => (string)$decoded['mode'],
				'outputDir' => trim((string)($decoded['outputDir'] ?? '')),
				'namingPattern' => trim((string)($decoded['namingPattern'] ?? '{filename}.pdf')),
			];
		}
		return [
			'mode' => $operation,
			'outputDir' => '',
			'namingPattern' => '{filename}.pdf',
		];
	}

	public function validateOperation(string $name, array $checks, string $operation): void {
		$config = $this->parseOperationConfig($operation);
		if (!in_array($config['mode'], Operation::MODES, true)) {
			throw new UnexpectedValueException($this->l->t('Please choose a mode.'));
		}
	}

	public function getDisplayName(): string {
		return $this->l->t('XOPP 2 PDF conversion');
	}

	public function getDescription(): string {
		return $this->l->t('Convert Xournal / Xournal++ files into the PDF format on upload and write.');
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('workflow_xopp2pdf_converter', 'app.svg');
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if (!$event instanceof GenericEvent) {
			return;
		}
		try {
			if ($eventName === '\OCP\Files::postRename' || $eventName === '\OCP\Files::postCopy') {
				/** @var Node $oldNode */
				[, $node] = $event->getSubject();
			} else {
				$node = $event->getSubject();
			}
			/** @var Node $node */

			// '', admin, 'files', 'path/to/file.txt'
			[,, $folder,] = explode('/', $node->getPath(), 4);
			if ($folder !== 'files' || $node instanceof Folder) {
				return;
			}

			// avoid converting pdfs into pdfs - would become infinite
			// also some types we know would not succeed
			if ($node->getMimetype() === 'application/pdf'
				|| $node->getMimePart() === 'video'
				|| $node->getMimePart() === 'audio'
			) {
				return;
			}

			// Check for .noconvert or .noexport in current folder or parent folders
			if ($this->isExcludedByMarker($node)) {
				$this->logger->debug('Skipping conversion for {file} due to marker file (.noconvert/.noexport)', [
					'app' => 'workflow_xopp2pdf_converter',
					'file' => $node->getPath(),
				]);
				return;
			}

			$matches = $ruleMatcher->getFlows(false);
			$originalFileMode = $targetPdfMode = null;
			$outputDir = '';
			$namingPattern = '{filename}.pdf';

			foreach ($matches as $match) {
				$config = $this->parseOperationConfig($match['operation']);
				$fileModes = explode(';', $config['mode']);
				if ($originalFileMode !== 'keep') {
					$originalFileMode = $fileModes[0];
				}
				if ($targetPdfMode !== 'preserve') {
					$targetPdfMode = $fileModes[1];
				}
				if (!empty($config['outputDir']) && empty($outputDir)) {
					$outputDir = $config['outputDir'];
				}
				if (!empty($config['namingPattern']) && $namingPattern === '{filename}.pdf') {
					$namingPattern = $config['namingPattern'];
				}
				if ($originalFileMode === 'keep' && $targetPdfMode === 'preserve') {
					// most conservative setting, no need to look into other modes
					break;
				}
			}

			if (!empty($originalFileMode) && !empty($targetPdfMode)) {
				$this->jobList->add(Convert::class, [
					'path' => $node->getPath(),
					'originalFileMode' => $originalFileMode,
					'targetPdfMode' => $targetPdfMode,
					'outputDir' => $outputDir,
					'namingPattern' => $namingPattern,
				]);

				$this->logger->info('Queued XOPP to PDF conversion for {file} (mode: {originalFileMode}/{targetPdfMode}, outputDir: "{outputDir}", pattern: "{namingPattern}")', [
					'app' => 'workflow_xopp2pdf_converter',
					'file' => $node->getPath(),
					'originalFileMode' => $originalFileMode,
					'targetPdfMode' => $targetPdfMode,
					'outputDir' => $outputDir,
					'namingPattern' => $namingPattern,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error('Error during XOPP conversion flow event handling: {msg}', [
				'app' => 'workflow_xopp2pdf_converter',
				'msg' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	public function isExcludedByMarker(Node $node): bool {
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
		} catch (Throwable $e) {
		}
		return false;
	}

	public function getEntityId(): string {
		return File::class;
	}
}
