<?php
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\App;
use Elabftw\Elabftw\DisplayParams;
use Elabftw\Elabftw\Tools;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\ControllerInterface;
use Elabftw\Models\AbstractEntity;
use Elabftw\Models\Revisions;
use Elabftw\Models\TeamGroups;
use Elabftw\Models\Templates;
use Elabftw\Services\Check;
use function is_array;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * For experiments.php
 */
abstract class AbstractEntityController implements ControllerInterface
{
    /** @var App $App instance of App */
    protected $App;

    /** @var AbstractEntity $Entity instance of AbstractEntity */
    protected $Entity;

    /** @var Templates $Templates instance of Templates */
    protected $Templates;

    /** @var array $categoryArr array of category (status or item type) */
    protected $categoryArr = array();

    /**
     * Constructor
     *
     * @param App $app
     * @param AbstractEntity $entity
     */
    public function __construct(App $app, AbstractEntity $entity)
    {
        $this->App = $app;
        $this->Entity = $entity;
        $this->Templates = new Templates($entity->Users);
    }

    /**
     * Get the Response object from the Request
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        // VIEW
        if ($this->App->Request->query->get('mode') === 'view') {
            return $this->view();
        }

        // EDIT
        if ($this->App->Request->query->get('mode') === 'edit') {
            return $this->edit();
        }

        // CREATE
        if ($this->App->Request->query->has('create')) {
            $id = $this->Entity->create((int) $this->App->Request->query->get('tpl'));
            return new RedirectResponse('?mode=edit&id=' . (string) $id);
        }

        // DEFAULT MODE IS SHOW
        return $this->show();
    }

    /**
     * Show mode (several items displayed). Default view.
     *
     * @return Response
     */
    public function show(bool $isSearchPage = false): Response
    {
        // VISIBILITY LIST
        $TeamGroups = new TeamGroups($this->Entity->Users);

        // CATEGORY FILTER
        if (Check::id((int) $this->App->Request->query->get('cat')) !== false) {
            $this->Entity->addFilter('categoryt.id', $this->App->Request->query->get('cat'));
        }

        // TAG FILTER
        if (!empty($this->App->Request->query->get('tags')[0])) {
            // get all the ids with that tag
            $tagsFromGet = $this->App->Request->query->get('tags') ?? array();
            if (is_array($tagsFromGet)) {
                $ids = $this->Entity->Tags->getIdFromTags($tagsFromGet, (int) $this->App->Users->userData['team']);
                $idFilter = ' AND (';
                foreach ($ids as $id) {
                    $idFilter .= 'entity.id = ' . $id . ' OR ';
                }
                $trimmedFilter = rtrim($idFilter, ' OR ') . ')';
                // don't add it if it's empty (for instance we search in items for a tag that only exists on experiments)
                if ($trimmedFilter === ' AND ()') {
                    throw new ImproperActionException(_("Sorry. I couldn't find anything :("));
                }
                $this->Entity->idFilter = $trimmedFilter;
            }
        }

        // create the DisplayParams object from the query
        $DisplayParams = new DisplayParams();
        $DisplayParams->adjust($this->App);

        // only show public to anon
        if ($this->App->Session->get('anon')) {
            $this->Entity->addFilter('entity.canread', 'public');
        }

        $itemsArr = $this->getItemsArr($DisplayParams->searchType);
        // get tags separately
        $tagsArr = array();
        if (!empty($itemsArr)) {
            $tagsArr = $this->Entity->getTags($itemsArr);
        }
        // get all the tags for the top search bar
        $tagsArrForSelect = $this->Entity->Tags->readAll();

        // store the query parameters in the Session
        $this->App->Session->set('lastquery', $this->App->Request->query->all());

        $template = 'show.html';

        $renderArr = array(
            'DisplayParams' => $DisplayParams,
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'pinnedArr' => $this->Entity->Pins->getPinned(),
            'itemsArr' => $itemsArr,
            // generate light show page
            'searchPage' => $isSearchPage,
            'searchType' => $isSearchPage ? 'something' : $DisplayParams->searchType,
            'tagsArr' => $tagsArr,
            'tagsArrForSelect' => $tagsArrForSelect,
            'templatesArr' => $this->Templates->readForUser(),
            'visibilityArr' => $TeamGroups->getVisibilityList(),
        );
        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));

        return $Response;
    }

    /**
     * Get the items
     *
     * @return array
     */
    abstract protected function getItemsArr(string $searchType): array;

    /**
     * View mode (one item displayed)
     *
     * @return Response
     */
    protected function view(): Response
    {
        $this->Entity->setId((int) $this->App->Request->query->get('id'));
        $this->Entity->canOrExplode('read');

        // REVISIONS
        $Revisions = new Revisions($this->Entity);

        $template = 'view.html';

        // the mode parameter is for the uploads tpl
        $renderArr = array(
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'commentsArr' => $this->Entity->Comments->readAll(),
            'linksArr' => $this->Entity->Links->readAll(),
            'mode' => 'view',
            'revNum' => $Revisions->readCount(),
            'stepsArr' => $this->Entity->Steps->readAll(),
            'templatesArr' => $this->Templates->readForUser(),
            'timestampInfo' => $this->Entity->getTimestampInfo(),
            'uploadsArr' => $this->Entity->Uploads->readAll(),
        );

        // RELATED ITEMS AND EXPERIMENTS
        if ($this->Entity->type === 'items') {
            ['items' => $renderArr['relatedItemsArr'],
                'experiments' => $renderArr['relatedExperimentsArr']
            ] = $this->Entity->Links->readRelated();
        }

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));

        return $Response;
    }

    /**
     * Edit mode
     *
     * @return Response
     */
    protected function edit(): Response
    {
        $this->Entity->setId((int) $this->App->Request->query->get('id'));
        // check permissions
        $this->Entity->canOrExplode('write');
        // a locked entity cannot be edited
        if ($this->Entity->entityData['locked']) {
            throw new ImproperActionException(_('This item is locked. You cannot edit it!'));
        }

        // REVISIONS
        $Revisions = new Revisions($this->Entity);

        // VISIBILITY ARR
        $TeamGroups = new TeamGroups($this->Entity->Users);

        $template = 'edit.html';

        $renderArr = array(
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'lang' => Tools::getCalendarLang($this->App->Users->userData['lang'] ?? 'en_GB'),
            'linksArr' => $this->Entity->Links->readAll(),
            'maxUploadSize' => Tools::getMaxUploadSize(),
            'mode' => 'edit',
            'revNum' => $Revisions->readCount(),
            'stepsArr' => $this->Entity->Steps->readAll(),
            'templatesArr' => $this->Templates->readForUser(),
            'uploadsArr' => $this->Entity->Uploads->readAll(),
            'visibilityArr' => $TeamGroups->getVisibilityList(),
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));
        return $Response;
    }
}
