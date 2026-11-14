<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WorkflowXoppToPdfConverter\Listener;

use OCA\WorkflowXoppToPdfConverter\Operation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use Psr\Container\ContainerInterface;

/**
 * @template-implements IEventListener<RegisterOperationsEvent>
 */
class RegisterFlowOperationsListener implements IEventListener {
	private ContainerInterface $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterOperationsEvent) {
			return;
		}
		try {
			$operation = $this->container->get(Operation::class);
			$event->registerOperation($operation);
		} catch (\Throwable $e) {
			try {
				$jobList = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
				$l = \OCP\Server::get(\OCP\IL10N::class);
				$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
				$operation = new Operation($jobList, $l, $urlGenerator);
				$event->registerOperation($operation);
			} catch (\Throwable $fallbackError) {
			}
		}
		Util::addScript('workflow_xopp2pdf_converter', 'workflow_xopp2pdf_converter-main');
	}
}
