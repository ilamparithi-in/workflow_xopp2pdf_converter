<?php

declare(strict_types=1);

namespace OCA\WorkflowXoppToPdfConverter\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;

/**
 * @template-implements IEventListener<LoadSettingsScriptsEvent>
 */
class LoadSettingsScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadSettingsScriptsEvent) {
			return;
		}
		Util::addScript('workflow_xopp2pdf_converter', 'workflow_xopp2pdf_converter-main');
	}
}
