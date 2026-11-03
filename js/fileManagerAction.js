/**
 * @file plugins/generic/docxConverter/js/fileManagerAction.js
 *
 * Copyright (c) 2014-2026 Simon Fraser University Library
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2.
 *
 * @brief Adds the "Convert to JATS XML" action to the OJS 3.5 workflow file managers.
 *
 * OJS 3.5 lists workflow files with the Vue FileManager component, whose state lives in
 * the Pinia stores "fileManager_<namespace>". The action is added by extending
 * getItemActions() and registering a store function with the same name as the action
 * (the file manager calls store[action.name]({file}) when the action is clicked).
 */
(function () {
	var cfg = window.pkpDocxConverter;
	if (typeof pkp === 'undefined' || !pkp.registry || !cfg) {
		return;
	}

	var ACTION_NAME = 'docxConverterParse';

	function notify(message, type) {
		if (pkp.eventBus) {
			pkp.eventBus.$emit('notify', message, type);
		}
	}

	function isConvertible(file) {
		return !!file && cfg.mimetypes.indexOf(String(file.mimetype || '').toLowerCase()) !== -1;
	}

	Object.keys(cfg.namespaces).forEach(function (namespace) {
		var storeId = 'fileManager_' + namespace;
		var stageId = cfg.namespaces[namespace];

		// 1. Offer the action only for DOCX files
		pkp.registry.storeExtendFn(storeId, 'getItemActions', function (actions, args) {
			if (!isConvertible(args && args.file)) {
				return actions;
			}
			return actions.concat([{
				label: cfg.i18n.action,
				name: ACTION_NAME,
				icon: 'DefaultDocument'
			}]);
		});

		// 2. Run the conversion and refresh the file list
		pkp.registry.storeExtend(storeId, function (context) {
			var store = context.store;
			store[ACTION_NAME] = function (actionArgs) {
				var file = actionArgs && actionArgs.file;
				if (!isConvertible(file)) {
					return;
				}

				var body = new FormData();
				body.append('csrfToken', pkp.currentUser.csrfToken);
				body.append('submissionId', file.submissionId);
				body.append('submissionFileId', file.id);
				body.append('stageId', stageId);

				return fetch(cfg.parseUrl, {
					method: 'POST',
					body: body,
					credentials: 'same-origin',
					headers: {'X-Requested-With': 'XMLHttpRequest'}
				})
					.then(function (response) {
						// A server error may not return JSON: fall back to the generic error message
						return response.json().catch(function () { return null; }).then(function (data) {
							if (!response.ok || !data || !data.status) {
								throw new Error((data && typeof data.content === 'string' && data.content) || cfg.i18n.error);
							}
							return data;
						});
					})
					.then(function () {
						notify(cfg.i18n.success, 'success');
						store.fetchFiles();
					})
					.catch(function (error) {
						notify(error && error.message ? error.message : cfg.i18n.error, 'warning');
					});
			};
		});
	});
})();
