<?php

namespace Twohill\NestedControllers;


use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\View\SSViewer;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;


/**
 * NestedModelController allows the control of objects that are related to
 * one another via sub-urls
 *
 *
 * Based off SilverStripe's Generic Views Module
 * http://www.silverstripe.org/generic-views-module/
 * By Ingo Schommer
 */
class NestedCollectionController extends Controller
{

    /**
     * @config
     */
    private static $recordController = NestedModelController::class;

    /**
     * @config
     */
    private static $recordType = DataObject::class;

    /**
     * @config
     */
    private static $results_per_page = 20;

    /**
     * @config
     */
    private static $sort_on = "Name";

    /**
     * @config
     */
    private static $breadcrumbs_delimiter = " &raquo; ";

    protected $crumbs = array();
    protected $parentController;
    protected $urlSegment;
    protected $request;
    public $Title;

    private static $url_handlers = array(
        '' => 'index',
        '$Action' => 'handleActionOrID',
    );

    private static $allowed_actions = array(
        'handleActionOrID',
    );

    /**
     * Delegate to different control flow, depending on whether the
     * URL parameter is a number (record id) or string (action).
     *
     * @param HTTPRequest $request
     * @return \SilverStripe\Control\HTTPResponse|\SilverStripe\ORM\FieldType\DBHTMLText
     */
    function handleActionOrID($request)
    {
        if (is_numeric($request->param('Action'))) {
            $controller = $this->config()->recordController;
            return new $controller($this, $this->getRecordType(), $request);
        } else {
            return $this->handleAction($request, null);
        }
    }

    /**
     * The collection controller needs to be passed a parent controller,
     * usually this is a page
     *
     * @param Controller $parentController
     * @param HTTPRequest $request
     */
    public function __construct(Controller $parentController, HTTPRequest $request)
    {
        $this->parentController = $parentController;
        parent::__construct();
        if ($request) {
            $this->request = $request;
            $this->urlSegment = $request->latestParam('Action');
            $this->addCrumb('View ' . $this->getPluralName(), $this->Link());
        }
    }

    /**
     * Overloading __get() and __call() to support nested controllers,
     * e.g. so we can still get the main site menu
     * Also allows RecordType() to return the currentRecord
     * @param $field
     * @return mixed
     */
    public function __get($field)
    {
        if ($this->hasMethod($funcName = "get$field")) {
            return $this->$funcName();
        } else {
            if ($this->hasField($field)) {
                return $this->getField($field);
            } else {
                if ($this->failover) {
                    return $this->failover->$field;
                } else {
                    if ($this->parentController) {
                        return $this->parentController->__get($field);
                    }
                }
            }
        }
    }

    public function __call($funcName, $args)
    {
        if ($this->hasMethod($funcName)) {
            return call_user_func_array(array(&$this, $funcName), $args);
        } else {
            if ($this->parentController->hasMethod($funcName)) {
                return call_user_func_array(array(&$this->parentController, $funcName), $args);
            }
        }
    }

    /**
     * Returns a link to this controller
     *
     * @param string $action Optional action
     * @return string
     */
    public function Link($action = null)
    {
        return Controller::join_links($this->parentController->Link(), "/{$this->urlSegment}/{$action}");
    }

    /**
     * Gets all the records and returns them, paginated
     *
     * @return PaginatedList
     * @throws \Exception
     */
    public function getAllRecords()
    {
        /* @var DataObject $recordType */
        $recordType = $this->getRecordType();
        $all = $recordType::get()->filterByCallBack(function (DataObject $obj) {
            return $obj->canView();
        });

        return new PaginatedList($all, $this->request);
    }

    /**
     * Gets all editable records and returns them, paginated
     *
     * @return PaginatedList
     * @throws \Exception
     */
    public function getEditableRecords()
    {
        $recordType = $this->getRecordType();
        $all = $recordType::get()->filterByCallBack(function (DataObject $obj) {
            return $obj->canEdit();
        });

        return new PaginatedList($all, $this->request);
    }


    /**
     * Subclasses should specify the types of records they work with
     */
    public function getRecordType()
    {
        return $this->config()->recordType;
    }

    /**
     * Gets the singular name of the type of record the controller works with
     *
     * @return string
     */
    public function getSingularName()
    {
        return singleton($this->getRecordType())->singular_name();
    }

    /**
     * Gets the plural name of the type of record the controller works with
     *
     * @return string
     */
    public function getPluralName()
    {
        return singleton($this->getRecordType())->plural_name();
    }

    /**
     * Uses the default template to show an index of all the records
     */
    public function index($request)
    {
        return $this;
    }

    /**
     * Checks if the current member can create a new record
     */
    public function canCreate($member = null)
    {
        return singleton($this->getRecordType())->canCreate($member);
    }

    /**
     * URL method to create a new record
     * @param HTTPRequest $request
     * @return \SilverStripe\View\ViewableData_Customised|void
     * @throws HTTPResponse_Exception
     */
    public function create_new($request)
    {
        if (!$this->canCreate()) {
            return $this->httpError(403, "You do not have permission to create new " . $this->getPluralName());
        }
        $this->addCrumb('Create new ' . $this->getSingularName());
        return $this->customise(['Form' => $this->CreationForm()]);
    }

    /**
     * If a parentcontroller exists, use its main template,
     * and mix in specific collectioncontroller subtemplates.
     * @param string|int $action
     * @return SSViewer
     */
    function getViewer($action)
    {
        if ($this->parentController) {
            if (is_numeric($action)) {
                $action = 'view';
            }
            $viewer = $this->parentController->getViewer($action);
            $layoutTemplate = null;
            // action-specific template with template identifier, e.g. themes/mytheme/templates/Layout/MyModel_view.ss
            $layoutTemplate = SSViewer::getTemplateFileByType($this->getRecordType() . "_$action",
                'Layout');
            // generic template with template identifier, e.g. themes/mytheme/templates/Layout/MyModel.ss
            if (!$layoutTemplate) {
                $layoutTemplate = SSViewer::getTemplateFileByType($this->getRecordType(), 'Layout');
            }

            // fallback to controller classname, e.g. iwidb/templates/Layout/NestedCollectionController.ss
            $parentClass = static::class;
            while ($parentClass != Controller::class && !$layoutTemplate) {
                $layoutTemplate = SSViewer::getTemplateFileByType("{$parentClass}_$action", 'Layout');
                if (!$layoutTemplate) {
                    $layoutTemplate = SSViewer::getTemplateFileByType($parentClass, 'Layout');
                }
                $parentClass = get_parent_class($parentClass);
            }
            if ($layoutTemplate) {
                $viewer->setTemplateFile('Layout', $layoutTemplate);
            }
            return $viewer;
        } else {
            return parent::getViewer($action);
        }
    }

    /**
     * Scaffolds a form for creating managed data types
     *
     * @return Form
     */
    public function CreationForm()
    {
        /** @var FieldList $fields */
        $fields = singleton($this->getRecordType())->getFrontEndFields();
        $fields->push(new HiddenField('ID'));
        $form = new Form(
            $this,
            'CreationForm',
            $fields,
            new FieldList(
                new FormAction('doSave', 'Save'),
                new FormAction('doCancel', 'Cancel')
            ));
        return $form;
    }

    /**
     * Function for saving the form
     */
    public function doSave($data, $form)
    {
        $recordType = $this->getRecordType();
        $record = $recordType::create();
        $form->saveInto($record);
        $record->write();
        $this->redirect($this->Link());
    }

    /**
     * URL function for cancelling creating/editing an object
     */
    public function doCancel()
    {
        $this->redirect($this->Link());
    }

    /**
     * Return a nice error, if possible
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
     * Maintains breadcrumbs down the nested chain
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
     * Extends breadcrumbs to provide links down the nested chain
     *
     * @return array
     */
    public function Breadcrumbs()
    {
        $parts = explode(self::$breadcrumbs_delimiter, $this->parentController->Breadcrumbs());
        // The last part is never a link, need to recreate
        array_pop($parts);
        array_push($parts,
            '<a href="' . $this->parentController->Link() . '">' . $this->parentController->Title . '</a>');

        //Merge
        array_pop($this->crumbs);
        if ($this->Title) {
            array_push($this->crumbs, $this->Title);
        }

        $parts = array_merge($parts, $this->crumbs);

        return implode(self::$breadcrumbs_delimiter, $parts);
    }

    /**
     * Generates a ArrayList of anonymous objects that can be used
     * to create an alphabet menu for all the items, linking only
     * when there are items begining with the letter, and providing
     * an indication of whether it is currently selected or not
     *
     * @return ArrayList
     */
    public function AlphabetPages()
    {
        $query = new SQLSelect();
        $pages = new ArrayList();

        $sortOn = $this->config()->sort_on;
        //Build an SQL query to get all the letters that people start with
        $query->select(array("Left($sortOn, 1) AS Letter"));
        $query->from($this->getRecordType());
        $query->groupBy(array('Letter'));
        $lettersFound = $query->execute()->column();

        $pages->push(
            new ArrayData(
                array(
                    'Letter' => 'All',
                    'Link' => $this->Link(),
                    'CurrentBool' => !isset($_GET['Letter']),
                )
            )
        );

        foreach (array(
                     'A',
                     'B',
                     'C',
                     'D',
                     'E',
                     'F',
                     'G',
                     'H',
                     'I',
                     'J',
                     'K',
                     'L',
                     'M',
                     'N',
                     'O',
                     'P',
                     'Q',
                     'R',
                     'S',
                     'T',
                     'U',
                     'V',
                     'W',
                     'X',
                     'Y',
                     'Z',
                     '0'
                 ) as $letter) {

            $letterFound = in_array($letter, $lettersFound);

            $pages->push(
                new ArrayData(
                    array(
                        'Letter' => $letter,
                        'Link' => $letterFound ? $this->Link() . '?letter=' . $letter : false,
                        'CurrentBool' => isset($_GET['letter']) && $_GET['letter'] == $letter,
                    )

                )
            );

        }
        return $pages;
    }
}
