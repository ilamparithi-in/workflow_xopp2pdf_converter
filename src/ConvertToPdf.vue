<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="workflow-xopp2pdf-container">
		<div class="setting-item">
			<label class="setting-label">{{ t('workflow_xopp2pdf_converter', 'Conversion mode') }}</label>
			<NcSelect :value="selectedOption"
				:options="options"
				track-by="id"
				label="text"
				@input="onModeChange" />
		</div>
		<div class="setting-item">
			<NcTextField :value="config.outputDir"
				:label="t('workflow_xopp2pdf_converter', 'Output directory (optional)')"
				:placeholder="t('workflow_xopp2pdf_converter', 'e.g. PDFs or /Exports/PDFs (blank = same folder)')"
				@update:value="onOutputDirChange" />
		</div>
		<div class="setting-item">
			<NcTextField :value="config.namingPattern"
				:label="t('workflow_xopp2pdf_converter', 'PDF naming pattern (optional)')"
				:placeholder="t('workflow_xopp2pdf_converter', '{filename}.pdf (tokens: {filename}, {date}, {datetime}, {parent}, {user})')"
				@update:value="onNamingPatternChange" />
		</div>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

const pdfConvertOptions = [
	{
		id: 'keep;preserve',
		text: t('workflow_xopp2pdf_converter', 'Keep original, preserve existing PDFs'),
	},
	{
		id: 'keep;overwrite',
		text: t('workflow_xopp2pdf_converter', 'Keep original, overwrite existing PDF'),
	},
	{
		id: 'delete;preserve',
		text: t('workflow_xopp2pdf_converter', 'Delete original, preserve existing PDFs'),
	},
	{
		id: 'delete;overwrite',
		text: t('workflow_xopp2pdf_converter', 'Delete original, overwrite existing PDF'),
	},
]

export default {
	name: 'ConvertToPdf',
	components: {
		NcSelect,
		NcTextField,
	},
	props: {
		modelValue: {
			default: 'keep;preserve',
			type: String,
		},
	},
	emits: ['update:model-value'],
	data() {
		return {
			options: pdfConvertOptions,
		}
	},
	computed: {
		config() {
			if (typeof this.modelValue === 'string' && this.modelValue.trim().startsWith('{')) {
				try {
					const parsed = JSON.parse(this.modelValue)
					return {
						mode: parsed.mode || 'keep;preserve',
						outputDir: parsed.outputDir || '',
						namingPattern: parsed.namingPattern || '{filename}.pdf',
					}
				} catch (e) {
					// fallback
				}
			}
			return {
				mode: this.modelValue || 'keep;preserve',
				outputDir: '',
				namingPattern: '{filename}.pdf',
			}
		},
		selectedOption() {
			const found = pdfConvertOptions.find(option => option.id === this.config.mode)
			return found || pdfConvertOptions[0]
		},
	},
	methods: {
		emitChange(newConfig) {
			this.$emit('update:model-value', JSON.stringify({
				mode: newConfig.mode || 'keep;preserve',
				outputDir: (newConfig.outputDir || '').trim(),
				namingPattern: (newConfig.namingPattern || '{filename}.pdf').trim(),
			}))
		},
		onModeChange(option) {
			this.emitChange({
				...this.config,
				mode: option ? option.id : 'keep;preserve',
			})
		},
		onOutputDirChange(val) {
			this.emitChange({
				...this.config,
				outputDir: val,
			})
		},
		onNamingPatternChange(val) {
			this.emitChange({
				...this.config,
				namingPattern: val,
			})
		},
	},
}
</script>

<style scoped>
	.workflow-xopp2pdf-container {
		display: flex;
		flex-direction: column;
		gap: 12px;
		width: 100%;
		margin: 8px 0;
	}

	.setting-item {
		display: flex;
		flex-direction: column;
		width: 100%;
	}

	.setting-label {
		font-size: 13px;
		font-weight: 500;
		margin-bottom: 4px;
		color: var(--color-text-maxcontrast, #222);
	}

	.multiselect {
		width: 100%;
		margin: auto;
	}
</style>
