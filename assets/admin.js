(function($) {
	var previewDefaults = yoohwAN.previewDefaults;

	function updateChoiceStates() {
		$('.yoohw-advanced-notifications-choice').each(function() {
			var $choice = $(this);
			var isSelected = $choice.find('input').is(':checked');

			$choice.toggleClass('is-selected', isSelected);
		});
	}

	function toggleRecipientFields() {
		var source = $('#recipient_source').val();
		var product = source === yoohwAN.productSource;
		var registered = source === yoohwAN.registeredSource;
		var manual = source === yoohwAN.manualSource;
		function revealApplicable(selector, applicable) {
			var $row = $(selector);
			var hasValue = $row.find(':input').toArray().some(function(input) {
				var value = $(input).val();
				return Array.isArray(value) ? value.length > 0 : value !== null && String(value).trim() !== '';
			});
			// Preserve restored and newly edited selections. The server must reject
			// incompatible conditions rather than receiving a silently broadened form.
			$row.toggle(applicable || hasValue).toggleClass('an-inapplicable-selection', !applicable && hasValue);
			$row.find(':input').prop('disabled', false);
		}
		revealApplicable('#yoohw-advanced-notifications-products-row', product);
		revealApplicable('#yoohw-advanced-notifications-roles-row', registered);
		revealApplicable('#yoohw-advanced-notifications-manual-row', manual);
		revealApplicable('.an-order-filters', product || source === yoohwAN.customersSource);
		revealApplicable('.an-country-filter', !manual);
		$('#yoohw-advanced-notifications-customers-row').toggle(source === yoohwAN.customersSource);
	}

	function toggleScheduleFields() {
		var isScheduled = $('input[name="schedule_mode"]:checked').val() !== 'now';
		var $row = $('#yoohw-advanced-notifications-schedule-row');

		$row.toggle(isScheduled);
		$row.find(':input').prop('disabled', !isScheduled);
		updateChoiceStates();
	}

	function toggleTestEmailFields() {
		var isTestMode = $('#test_mode').is(':checked');
		var $row = $('#yoohw-advanced-notifications-test-email-row');

		$row.toggle(isTestMode);
		$row.find(':input').prop('disabled', !isTestMode);
		$('.an-test-submit').prop('hidden', !isTestMode);
		updatePrimaryActionText(isTestMode);
		updateChoiceStates();
	}

	function updatePrimaryActionText(isTestMode) {
		var $button = $('#yoohw-advanced-notifications-primary-action');
		var $help = $('#yoohw-advanced-notifications-action-help');

		if ($button.length) {
			$button.val(isTestMode ? $button.data('test-label') : $button.data('campaign-label'));
		}

		if ($help.length) {
			$help.text(isTestMode ? $help.data('test-text') : $help.data('campaign-text'));
		}
	}

	function getPreviewFrameDocument() {
		var frame = document.getElementById('yoohw-advanced-notifications-preview-frame');

		if (!frame) {
			return null;
		}

		return frame.contentDocument || (frame.contentWindow ? frame.contentWindow.document : null);
	}

	function initializeEmailPreviewFrame() {
		var frame = document.getElementById('yoohw-advanced-notifications-preview-frame');
		var template = $('#yoohw-advanced-notifications-preview-template-source').val();

		if (!frame || !template || !frame.contentWindow) {
			return;
		}

		var doc = frame.contentWindow.document;
		doc.open();
		doc.write(template);
		doc.close();
		setTimeout(updateEmailPreview, 50);
	}

	function getCampaignMessageContent() {
		var editor = window.tinymce ? window.tinymce.get('email_message') : null;

		if (editor && !editor.isHidden()) {
			return editor.getContent();
		}

		return $('#email_message').val() || '';
	}

	function getFallbackMessageHtml() {
		return $('<p></p>').text(previewDefaults.message).prop('outerHTML');
	}

	function cleanPreviewHtml(html) {
		var container = $('<div></div>').html(html || '');

		container.find('script').remove();
		container.find('*').each(function() {
			var node = this;

			$.each($.makeArray(node.attributes), function(index, attribute) {
				if (/^on/i.test(attribute.name)) {
					$(node).removeAttr(attribute.name);
				}
			});
		});

		return container.html();
	}

	function hasMeaningfulPreviewContent(html) {
		var container = $('<div></div>').html(html || '');

		if (container.text().trim()) {
			return true;
		}

		return container.find('img, table, hr, ul, ol, blockquote').length > 0;
	}

	function bindEmailEditorPreview() {
		var editor = window.tinymce ? window.tinymce.get('email_message') : null;

		if (!editor || editor.yoohwEmailNotifyPreviewBound) {
			return;
		}

		editor.on('keyup change input SetContent NodeChange', updateEmailPreview);
		editor.yoohwEmailNotifyPreviewBound = true;
	}

	function updateEmailPreview() {
		var subject = String($('#email_subject').val() || '').trim() || previewDefaults.subject;
		var heading = String($('#email_heading').val() || '').trim() || previewDefaults.heading;
		var messageHtml = getCampaignMessageContent();
		var doc = getPreviewFrameDocument();

		$('#yoohw-advanced-notifications-preview-subject').text(subject);

		if (!doc || !doc.body) {
			return;
		}

		var previewHeading = $(doc).find('#yoohw-advanced-notifications-preview-heading');

		if (previewHeading.length) {
			previewHeading.text(heading);
		} else {
			$(doc).find('h1:first').text(heading);
		}

		var previewMessage = $(doc).find('#yoohw-advanced-notifications-preview-message');

		if (previewMessage.length) {
			previewMessage.html(
				hasMeaningfulPreviewContent(messageHtml)
					? cleanPreviewHtml(messageHtml)
					: getFallbackMessageHtml()
			);
		}
	}

	function initializeComposeSteps() {
		var $form = $('.yoohw-advanced-notifications-campaign-form');
		var $panels = $form.find('[data-an-panel]');
		if (!$panels.length) { return; }
		var names = $panels.map(function() { return this.dataset.anPanel; }).get();
		var active = 0;
		function showStep(index, focus) {
			active = Math.max(0, Math.min(index, names.length - 1));
			$panels.prop('hidden', true).eq(active).prop('hidden', false);
			$form.find('[data-an-step]').removeAttr('aria-current').eq(active).attr('aria-current', 'step');
			$form.find('[data-an-previous]').prop('hidden', active === 0);
			$form.find('[data-an-next]').prop('hidden', active === names.length - 1);
			if (focus) {
				$form.find('[data-an-step]').eq(active).trigger('focus');
			}
			$(document.body).trigger('wc-enhanced-select-init');
			bindEmailEditorPreview();
		}
		$form.addClass('an-staged-compose');
		$form.find('.an-step-controls').prop('hidden', false);
		$form.find('[data-an-step]').on('click', function(event) {
			event.preventDefault();
			showStep(names.indexOf(this.dataset.anStep), false);
		});
		$form.find('[data-an-previous]').on('click', function() { showStep(active - 1, true); });
		$form.find('[data-an-next]').on('click', function() { showStep(active + 1, true); });
		// Native validation must reveal the actual required field before the
		// browser focuses it. Never disable fields merely to change steps.
		$form[0].addEventListener('invalid', function(event) {
			var panel = event.target.closest('[data-an-panel]');
			if (panel) { showStep(names.indexOf(panel.dataset.anPanel), false); }
		}, true);
		showStep(0, false);
	}

	function initializeCancelDialog() {
		var dialog = document.getElementById('an-cancel-dialog');
		if (!dialog || typeof dialog.showModal !== 'function') { return; }
		var submitter = null;
		$(document).on('click', 'button[name="operation"][value="cancel"]', function(event) {
			event.preventDefault();
			submitter = this;
			dialog.showModal();
			$(dialog).find('[data-an-dismiss]').trigger('focus');
		});
		$(dialog).find('[data-an-dismiss]').on('click', function() { dialog.close(); });
		$(dialog).find('[data-an-confirm-cancel]').on('click', function() {
			var form = submitter.form;
			dialog.close();
			// Keep the original action, campaign id, nonce and operation authority.
			var operation = document.createElement('input');
			operation.type = 'hidden'; operation.name = 'operation'; operation.value = 'cancel';
			form.appendChild(operation);
			form.requestSubmit();
		});
		dialog.addEventListener('close', function() { if (submitter) { submitter.focus(); } });
	}

	$(function() {
		$(document.body).trigger('wc-enhanced-select-init');
		initializeComposeSteps();
		initializeCancelDialog();
		initializeEmailPreviewFrame();
		toggleRecipientFields();
		toggleScheduleFields();
		toggleTestEmailFields();
		bindEmailEditorPreview();
		updateEmailPreview();
		setTimeout(function() {
			bindEmailEditorPreview();
			updateEmailPreview();
		}, 500);
		$('.yoohw-advanced-notifications-details').on('toggle', function() {
			if (this.open) {
				$(document.body).trigger('wc-enhanced-select-init');
			}
		});
	});
	$(window).on('pageshow', function() { toggleRecipientFields(); toggleScheduleFields(); toggleTestEmailFields(); });
	$(document).on('change', '.an-preview-width', function() {
		var $panel = $(this).closest('.yoohw-advanced-notifications-preview-panel');
		($panel.length ? $panel.find('iframe') : $('.an-personal-preview')).css('width', this.value).css('max-width', '100%');
	});
	$(document).on('change', '#an-template', function() {
		var option = this.options[this.selectedIndex];
		if (!this.value) { return; }
		$('#email_subject, #email_heading').val(option.dataset.subject);
		$('#email_message').val(option.dataset.body);
		var editor = window.tinymce ? window.tinymce.get('email_message') : null;
		if (editor) { editor.setContent(option.dataset.body); }
		updateEmailPreview();
	});
	$(document).on('submit', 'form', function() { if (window.tinymce) { window.tinymce.triggerSave(); } });
	$(document).on('change', '#recipient_source, .an-order-filters :input, .an-country-filter :input, #yoohw-advanced-notifications-roles-row :input, #yoohw-advanced-notifications-products-row :input, #yoohw-advanced-notifications-manual-row :input', toggleRecipientFields);
	$(document).on('change', 'input[name="schedule_mode"]', toggleScheduleFields);
	$(document).on('change', '#test_mode', toggleTestEmailFields);
	$(document).on('input change', '#email_subject, #email_heading, #email_message', updateEmailPreview);
	$(document).on('click', '#email_message-tmce, #email_message-html', function() {
		setTimeout(function() {
			bindEmailEditorPreview();
			updateEmailPreview();
		}, 150);
	});
	$(document).on('tinymce-editor-init', function(event, editor) {
		if (editor && editor.id === 'email_message') {
			bindEmailEditorPreview();
			updateEmailPreview();
		}
	});
})(jQuery);
