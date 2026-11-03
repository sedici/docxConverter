<?php

/**
 * @file plugins/generic/docxConverter/DocxToJatsPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University Library
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2.
 *
 * @brief main class of the DOCX to JATS XML Converter Plugin
 */

namespace APP\plugins\generic\docxConverter;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class DocxToJatsPlugin extends GenericPlugin
{
    /** Roles that can run the conversion (same rule as DOCXConverterHandler) */
    public const ALLOWED_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT];

    /**
     * File managers of the OJS 3.5 workflow (Vue) where the action is offered, by workflow stage
     */
    public const FILE_MANAGER_NAMESPACES = [
        'COPYEDITED_FILES' => WORKFLOW_STAGE_ID_EDITING,
        'PRODUCTION_READY_FILES' => WORKFLOW_STAGE_ID_PRODUCTION,
    ];

    /**
     * @copydoc Plugin::getDisplayName()
     */
    function getDisplayName(): string
    {
        return __('plugins.generic.docxToJats.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    function getDescription(): string
    {
        return __('plugins.generic.docxToJats.description');
    }

    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null): bool
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled()) {
                require_once __DIR__ . "/docxToJats/vendor/autoload.php";
                Hook::add('TemplateManager::display', [$this, 'addFileManagerAction']);
                Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
                $this->_registerTemplateResource();
            }
            return true;
        }
        return false;
    }

    /**
     * Get plugin URL.
     */
    function getPluginUrl($request): string
    {
        return $request->getBaseUrl() . '/' . $this->getPluginPath();
    }

    /**
     * Route docxParser/parse to the conversion handler.
     *
     * OJS 3.5 no longer supports define('HANDLER_CLASS'): the handler instance
     * must be returned through the 4th hook argument.
     */
    public function callbackLoadHandler($hookName, $args): bool
    {
        $page = $args[0];
        $op = $args[1];

        if ($page === 'docxParser' && $op === 'parse') {
            $args[3] = new DOCXConverterHandler($this);
            return true;
        }
        return false;
    }

    /**
     * Add the "Convert to JATS XML" action to the workflow file managers.
     *
     * In OJS 3.5 the workflow files are listed by the Vue FileManager component
     * (the old grids are no longer rendered), so the action is added in JS by
     * extending the fileManager_<namespace> Pinia stores.
     */
    public function addFileManagerAction($hookName, $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$context || !$user || !$user->hasRole(self::ALLOWED_ROLES, $context->getId())) {
            return false;
        }

        $dispatcher = $request->getDispatcher();
        $config = [
            'parseUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'docxParser', 'parse'),
            'mimetypes' => static::getSupportedMimetypes(),
            'namespaces' => self::FILE_MANAGER_NAMESPACES,
            'i18n' => [
                'action' => __('plugins.generic.docxToJats.button.parseDocx'),
                'success' => __('plugins.generic.docxToJats.notification.success'),
                'error' => __('plugins.generic.docxToJats.notification.error'),
            ],
        ];

        $templateMgr->addJavaScript(
            'docxConverterConfig',
            'window.pkpDocxConverter = ' . json_encode($config) . ';',
            ['inline' => true, 'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );
        $templateMgr->addJavaScript(
            'docxConverterFileManager',
            $this->getPluginUrl($request) . '/js/fileManagerAction.js',
            // After js/build.js (STYLE_SEQUENCE_LATE), which defines pkp.registry
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        return false;
    }

    /**
     * Retrieves the list of allowed workflow stages.
     */
    public function getAllowedWorkflowStages(): array
    {
        return [
            WORKFLOW_STAGE_ID_EDITING,
            WORKFLOW_STAGE_ID_PRODUCTION
        ];
    }

    /**
     * MIME type supported by the plugin for conversion
     */
    public static function getSupportedMimetypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            // OJS identifies Google Docs files exported in DOCX format as having this MIME type
            'application/vnd.openxmlformats-officedocument.wordprocessingml.documentapplication/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
    }
}

// For backwards compatibility -- expect this to be removed approx. OJS/OMP/OPS 3.6
if (!PKP_STRICT_MODE) {
    class_alias('APP\plugins\generic\docxConverter\DocxToJatsPlugin', '\DocxToJatsPlugin');
}
