<?php
namespace TechDivision\Jobs\GoogleApi\Controller;

/*
* This file is part of the TechDivision.Job package.
*
* TechDivision - neos@techdivision.com
*
* This package is Open Source Software. For the full copyright and license
* information, please view the LICENSE file which was distributed with this
* source code.
*/

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Exception;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Service\LinkingService;
use TechDivision\Jobs\GoogleApi\Service\JobPublishingService;
use TechDivision\Jobs\GoogleApi\Service\UriBuilderService;

/**
 * Class BackendModuleController
 * @package TechDivision\Jobs\GoogleApi\Controller
 */
class BackendModuleController extends ActionController
{

    /**
     * @Flow\Inject
     * @var UriBuilderService
     */
    protected $uriBuilderService;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @var SiteRepository
     * @Flow\Inject
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var JobPublishingService
     */
    protected $jobPublishingService;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * Assign all available sites to view
     */
    public function indexAction()
    {
        $sites = $this->siteRepository->findAll();
        $siteSelectOptions = [];
        /** @var Site $site */
        foreach ($sites as $site) {
            $siteSelectOptions[$site->getNodeName()] = $site->getNodeName() . " - " . $site->getName();
        }

        $this->view->assignMultiple(array(
            'sites' => $siteSelectOptions,
        ));
    }

    /**
     * Assign selected site-package and available dimension to view
     * @param string $siteNodeName - Selected site-package which has been assigned to view before
     */
    public function showAvailableDimensionsAction($siteNodeName)
    {
        $availableDimensions = array();
        $availableRootNodes = $this->getContentDimensionRootNodes($siteNodeName);

        foreach ($availableRootNodes as $rootNode) {
            array_push($availableDimensions, $rootNode->getDimensions()['language'][0]);
        }

        $this->view->assignMultiple(array(
            'site' => $siteNodeName,
            'dimensions' => $availableDimensions,
        ));
    }

    /**
     * Assign all findable JobPostings, site-package-name and site-dimension to view
     * @param string $siteNodeName
     * @param string $siteDimension
     * @throws \Neos\Eel\Exception
     */
    public function showJobPostingsForSiteAction($siteNodeName, $siteDimension)
    {
        $selectedRootNode = $this->getSelectedRootNodeByDimension($siteNodeName, $siteDimension);
        $query = new FlowQuery([$selectedRootNode]);
        $jobPostings = $query->find('[instanceof TechDivision.Jobs:Document.JobPosting]')->get();

        $this->view->assignMultiple(array(
            'jobPostings' => $jobPostings,
            'siteNode' => $siteNodeName,
            'siteDimension' => $siteDimension
        ));
    }

    /**
     * Send all selected JobPostings to google
     * @param string $siteNodeName
     * @param string $siteDimension
     * @param array $nodesToUpdate
     * @throws \Exception
     */
    public function sendJobPostingUrisToGoogleApiAction($siteNodeName, $siteDimension ,$nodesToUpdate)
    {
        $nodesUriToUpdate = array();
        $contentContext = $this->getContentContextByDimension($siteDimension);
        foreach ($nodesToUpdate as $nodeToUpdate) {
            $node = $contentContext->getNodeByIdentifier($nodeToUpdate);
            $this->uriBuilderService->buildUriForNodeData($node->getNodeData());
            array_push($nodesUriToUpdate, $this->uriBuilderService->getLastUri());
        }

        foreach ($nodesUriToUpdate as $nodeUriToUpdate) {
            $googleResponse = $this->jobPublishingService->updateJobPosting($nodeUriToUpdate);
        }

        $this->view->assignMultiple(array(
            'googleResponse' => $googleResponse,
        ));
    }

    /**
     * Get rootNode for each dimension
     * @param string $siteNodeName
     * @return array
     */
    private function getContentDimensionRootNodes($siteNodeName) {
        $combinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        $dimensionRootNodes = array();

        foreach ($combinations as $combination) {
            $context = $this->contextFactory->create([
                'workspaceName' => 'live',
                'dimensions' => $combination,
                'invisibleContentShown' => false,
                'removedContentShown' => false,
                'inaccessibleContentShown' => false
            ]);
            $rootNode = $context->getNode('/sites/' . $siteNodeName);

            if(!empty($rootNode) && $rootNode !== null) {
                array_push($dimensionRootNodes, $rootNode);
            }
        }

        return $dimensionRootNodes;
    }

    /**
     * Select one rootNode from all available rootNodes by dimension
     * @param string $siteNodeName
     * @param string $siteDimension
     * @return Node $selectedRootNode
     */
    private function getSelectedRootNodeByDimension($siteNodeName, $siteDimension) {
        $availableRootNodes = $this->getContentDimensionRootNodes($siteNodeName);
        foreach ($availableRootNodes as $rootNode) {
            if($rootNode->getDimensions()['language'][0] === $siteDimension) {
                $selectedRootNode = $rootNode;
            }
        }
        return $selectedRootNode;
    }

    /**
     * @param string $siteDimension
     * @return Context $context
     */
    private function getContentContextByDimension($siteDimension) {
        $combinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        foreach ($combinations as $combination) {
            if($combination['language'][0] === $siteDimension) {
                $dimension = $combination;
            }
        }

        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => $dimension,
            'invisibleContentShown' => false,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false
        ]);

        return $context;
    }

}
