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
		var isProductSource = $('#recipient_source').val() === yoohwAN.productSource;
		var $row = $('#yoohw-advanced-notifications-products-row');

		$row.toggle(isProductSource);
		$row.find(':input').prop('disabled', !isProductSource);
		var source = $('#recipient_source').val();
		var isRegisteredSource = source === yoohwAN.registeredSource;
		var isManualSource = source === yoohwAN.manualSource;
		$('#yoohw-advanced-notifications-roles-row').toggle(isRegisteredSource).find(':input').prop('disabled', !isRegisteredSource);
		$('#yoohw-advanced-notifications-manual-row').toggle(isManualSource).find(':input').prop('disabled', !isManualSource);
		$('#yoohw-advanced-notifications-customers-row').toggle(source === yoohwAN.customersSource);
		$('.an-order-filters').toggle(isProductSource || source === yoohwAN.customersSource).find(':input').prop('disabled', !(isProductSource || source === yoohwAN.customersSource));
		$('.an-country-filter').toggle(!isManualSource).find(':input').prop('disabled', isManualSource);
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

	$(function() {
		$(document.body).trigger('wc-enhanced-select-init');
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
	$(document).on('change', '.an-preview-width', function() { $('.an-personal-preview').css('width', this.value); });
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
	$(document).on('change', '#recipient_source', toggleRecipientFields);
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
