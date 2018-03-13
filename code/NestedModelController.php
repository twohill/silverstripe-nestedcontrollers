<?php

namespace Twohill\NestedControllers;


use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\View\SSViewer;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Control\HTTPResponse_Exception;


/**
 * NestedModelController allows the control of objects that are related to
 * one another via sub-urls
 *
 */
class NestedModelController extends Controller
{
    protected $parentController;
    protected $currentRecord;
    protected $recordType;

    public static $breadcrumbs_delimiter = " &raquo; ";

    protected $crumbs = array();
    public $Title;

    private static $allowed_actions = array(
        'edit',
        'view',
        'delete',
        'Form',
    );
    protected static $url_handlers = array(
        '' => 'index',
        '$Action//$ID' => 'handleAction'
    );

    /**
     * The model controller needs to be passed the required info:
     *
     * @param Controller $parentController the NestedCollectionController
     * @param string $recordType the type of record to work with
     * @param SS_HTTPRequest $request
     */
    public function __construct(Controller $parentController, $recordType, HTTPRequest $request)
    {
        $this->parentController = $parentController;
        $this->recordType = $recordType;

        if (is_numeric($request->latestParam('Action'))) {
            $this->currentRecord = $recordType::get()->byID($request->latestParam('Action'));
            if ($this->currentRecord) {
                $this->addCrumb($this->currentRecord->forTemplate(), $this->Link());
            }
        }

        parent::__construct();
    }

    /**
     * Overloading __get() and __call() to support nested controllers,
     * e.g. so we can still get the main site menu
     * Also allows RecordType() to return the currentRecord
     */
    public function __get($field)
    {
        if ($this->hasMethod($funcName = "get$field")) {
            return $this->$funcName();
        } else if ($this->hasField($field)) {
            return $this->getField($field);
        } else if ($field == $this->recordType) {
            return $this->currentRecord;
        } else if ($this->failover) {
            return $this->failover->$field;
        } else if ($this->parentController) {
            return $this->parentController->__get($field);
        }
    }

    public function __call($funcName, $args)
    {
        if ($this->hasMethod($funcName)) {
            return call_user_func_array(array(&$this, $funcName), $args);
        } else if ($this->parentController->hasMethod($funcName)) {
            return call_user_func_array(array(&$this->parentController, $funcName), $args);
        }
    }


    /**
     * Link fragment - appends the current record ID to the URL.
     *
     */
    public function Link()
    {
        if ($this->currentRecord) {
            return Controller::join_links($this->parentController->Link(), "/{$this->currentRecord->ID}");
        } else {
            return $this->parentController->Link();
        }

    }

    /**
     * Returns the record we're working with
     *
     * @return DataObject
     */
    public function getRecord()
    {
        return $this->currentRecord;
    }

    /**
     * Returns the view of the record
     *
     * @uses $this->view()
     */
    public function index($request)
    {
        return $this->view($request);
    }

    /**
     * Returns the view of the record
     */
    public function view($request)
    {
        if (!$this->currentRecord) {
            return $this->httpError(404, "{$this->recordType} not found");
        }
        if (!$this->currentRecord->canView()) {
            return $this->httpError(403, "You do not have permission to view this {$this->recordType}");
        }
        $form = $this->Form();
        $form->setActions(new FieldList());
        $form->makeReadonly();
        return $this->customise(array('Form' => $form));
    }

    /**
     * Returns a form for editing the record
     */
    public function edit($request)
    {
        if (!$this->currentRecord) {
            return $this->httpError(404, "{$this->recordType} not found");
        }
        if (!$this->currentRecord->canEdit()) {
            return $this->httpError(403, "You do not have permission to edit this {$this->recordType}");
        }
        $this->addCrumb('Edit ' . $this->currentRecord->forTemplate());
        return $this;
    }

    public function delete($request)
    {
        if (!$this->currentRecord) {
            return $this->httpError(404, "{$this->recordType} not found");
        }
        if (!$this->currentRecord->canDelete()) {
            return $this->httpError(403, "You do not have permission to edit this {$this->recordType}");
        }
        if ($request->param('ID') !== 'confirm') {
            return $this->customise(array('Title' => 'Delete ' . $this->currentRecord->singular_name()));
        } else {
            $this->currentRecord->delete();
            $this->redirect($this->parentController->Link());
        }
    }

    /**
     * If a parentcontroller exists, use its main template,
     * and mix in specific collectioncontroller subtemplates.
     */
    function getViewer($action)
    {
        if ($this->parentController) {
            if (is_numeric($action)) $action = 'view';
            $viewer = $this->parentController->getViewer($action);
            $layoutTemplate = null;
            // action-specific template with template identifier, e.g. themes/mytheme/templates/Layout/MyModel_view.ss
            $layoutTemplate = SSViewer::getTemplateFileByType("{$this->recordType}_$action", 'Layout');

            // generic template with template identifier, e.g. themes/mytheme/templates/Layout/MyModel.ss
            if (!$layoutTemplate) $layoutTemplate = SSViewer::getTemplateFileByType($this->recordType, 'Layout');

            // fallback to controller classname, e.g. iwidb/templates/Layout/NestedModelController.ss
            $parentClass = $this->class;
            while ($parentClass != Controller::class && !$layoutTemplate) {
                $layoutTemplate = SSViewer::getTemplateFileByType("{$parentClass}_$action", 'Layout');
                if (!$layoutTemplate) $layoutTemplate = SSViewer::getTemplateFileByType($parentClass, 'Layout');
                $parentClass = get_parent_class($parentClass);
            }

            $viewer->setTemplateFile('Layout', $layoutTemplate);

            return $viewer;
        } else {
            return parent::getViewer($action);
        }
    }

    /**
     * Scaffolds the fields required for editing the record
     *
     * @return Form
     */
    public function Form()
    {
        if ($this->currentRecord) {
            $fields = $this->currentRecord->getFrontEndFields();
            $required = $this->currentRecord->getRequiredFields();
        } else {
            $fields = singleton($this->recordType)->getFrontEndFields();
            $required = singleton($this->recordType)->getFrontEndFields();
        }

        $fields->push(new HiddenField('ID'));
        $form = new Form($this, Form::class, $fields, new FieldList(new FormAction('doSave', 'Save')), $required);
        if ($this->currentRecord) {
            $form->loadDataFrom($this->currentRecord);
        }
        return $form;
    }

    /**
     * Save the record
     */
    public function doSave($data, $form)
    {
        if (!$this->currentRecord) {
            $this->currentRecord = new $this->recordType();
        }
        $form->saveInto($this->currentRecord);
        $this->currentRecord->write();
        $this->redirect($this->Link());
    }

    /**
     * Cancels editing and returns to the view of the record
     */
    public function doCancel()
    {
        $this->redirect($this->Link());
    }

    /**
     * Show a pretty error, if possible
     *
     * @uses ErrorPage::response_for()
     */
    public function httpError($code, $message = null)
    {
        if ($this->request->isMedia() || !$response = ErrorPage::response_for($code)) {
            parent::httpError($code, $message);
        } else {
            throw new HTTPResponse_Exception($response);
        }
    }

    /**
     * Adds a breadcrumb action
     */
    public function addCrumb($title, $link = null)
    {
        $this->Title = $title;
        if ($link) {
            array_push($this->crumbs, "<a href=\"$link\">$title</a>");
        } else {
            array_push($this->crumbs, $title);
        }
    }

    /**
     * Build on the breadcrumbs to show the nested actions
     *
     * @return array
     */
    public function Breadcrumbs()
    {
        $parts = explode(self::$breadcrumbs_delimiter, $this->parentController->Breadcrumbs());
        // The last part is never a link, need to recreate
        array_pop($parts);
        array_push($parts, '<a href="' . $this->parentController->Link() . '">' . $this->parentController->Title . '</a>');

        //Merge
        array_pop($this->crumbs);
        array_push($this->crumbs, $this->Title);

        $parts = array_merge($parts, $this->crumbs);

        return implode(self::$breadcrumbs_delimiter, $parts);
    }
}