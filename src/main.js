/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import wrap from '@vue/web-component-wrapper'
import Vue from 'vue'

import ConvertToPdf from './ConvertToPdf.vue'

const customElementId = 'oca-workflow_xopp2pdf_converter-operation-convert'

if (typeof window !== 'undefined' && window.customElements && !window.customElements.get(customElementId)) {
	const ConvertToPdfComponent = wrap(Vue, ConvertToPdf)

	// In Vue 2, wrap doesn't support disabling shadow :(
	// Disable with a hack
	Object.defineProperty(ConvertToPdfComponent.prototype, 'attachShadow', { value() { return this } })
	Object.defineProperty(ConvertToPdfComponent.prototype, 'shadowRoot', { get() { return this } })

	window.customElements.define(customElementId, ConvertToPdfComponent)
}

function registerOperator() {
	if (typeof window !== 'undefined' && window.OCA && window.OCA.WorkflowEngine && typeof window.OCA.WorkflowEngine.registerOperator === 'function') {
		window.OCA.WorkflowEngine.registerOperator({
			id: 'OCA\\WorkflowXoppToPdfConverter\\Operation',
			operation: 'keep;preserve',
			element: customElementId,
			color: '#dc5047',
		})
		console.info('[workflow_xopp2pdf_converter] Operator registered successfully with OCA.WorkflowEngine')
		return true
	}
	return false
}

// Attempt immediate registration
if (!registerOperator()) {
	// Fallback to DOMContentLoaded, load event, and polling
	if (typeof document !== 'undefined') {
		document.addEventListener('DOMContentLoaded', registerOperator)
	}
	if (typeof window !== 'undefined') {
		window.addEventListener('load', registerOperator)
		let attempts = 0
		const timer = setInterval(() => {
			attempts++
			if (registerOperator() || attempts >= 50) {
				clearInterval(timer)
			}
		}, 100)
	}
}
