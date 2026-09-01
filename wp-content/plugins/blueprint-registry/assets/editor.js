(function ($) {
	'use strict';
	$(function () {
		if (window.BlueprintRegistryEditor && document.getElementById('bp_blueprint_json')) {
			var $textarea = $('#bp_blueprint_json');
			var editor = wp.codeEditor.initialize('bp_blueprint_json', window.BlueprintRegistryEditor);
			if ($textarea.prop('disabled')) {
				editor.codemirror.setOption('readOnly', 'nocursor');
			}
			$textarea.closest('form').on('submit', function () {
				editor.codemirror.save();
			});
		}
	});
})(jQuery);
