<?php
namespace TechDivision\Jobs\GoogleApi\Controller;

/*
* This file is part of the TechDivision.Jobs.GoogleApi package.
*
* TechDivision - neos@techdivision.com
*
* This package is Open Source Software. For the full copyright and license
* information, please view the LICENSE file which was distributed with this
* source code.
*/

use Google_Service_Exception;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Exception;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Service\LinkingService;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use TechDivision\Jobs\GoogleApi\Service\JobIndexingService;
use TechDivision\Jobs\GoogleApi\Service\JobPublishingService;
use TechDivision\Jobs\GoogleApi\Service\UriBuilderService;

/**
 * Class BackendModuleController
 * @package TechDivision\Jobs\GoogleApi\Controller
 */
class BackendModuleController extends ActionController
{
    use LoggerTrait;

    /**
     * @Flow\InjectConfiguration(path="options.logGoogleClientConfiguration")
     * @var bool
     */
    protected $logGoogleClientConfiguration;

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $jobIndexingLogger;

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
     * @var Translator
     */
    protected $translator;

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
     * @var JobIndexingService
     * @Flow\Inject
     */
    protected $jobIndexingService;

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

        $this->view->assignMultiple([
            'sites' => $siteSelectOptions,
        ]);
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

        $this->view->assignMultiple([
            'site' => $siteNodeName,
            'dimensions' => $availableDimensions,
        ]);
    }

    /**
     * Assign all findable JobPostings, site-package-name and site-dimension to view
     * @param string $siteNodeName
     * @param string $siteDimension
     * @throws \Neos\Eel\Exception
     */
    public function showJobPostingsForSiteAction($siteNodeName, $siteDimension)
    {
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $selectedRootNode = $this->getSelectedRootNodeByDimension($siteNodeName, $siteDimension);
        $query = new FlowQuery([$selectedRootNode]);
        $jobPostings = $query->find('[instanceof TechDivision.Jobs:Document.JobPosting]')->get();

        $this->view->assignMultiple([
            'jobPostings' => $jobPostings,
            'siteNode' => $siteNodeName,
            'siteDimension' => $siteDimension,
            'flashMessages' => $flashMessages
        ]);
    }

    /**
     * Send all selected JobPostingUris to google
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

        if (empty($nodeToUpdate)) {
            $message = $this->translateById('error.nodesToUpdateIsEmpty');
            $this->addFlashMessage('', $message, Message::SEVERITY_ERROR);
            $this->info($message);
        }

        foreach ($nodesUriToUpdate as $nodeUriToUpdate) {
            try {
                $this->info('Try to update ' . $nodeUriToUpdate);
                $this->jobPublishingService->updateJobPosting($nodeUriToUpdate);
                $message = $this->translateById('successful.nodesUpdated');
                $this->addFlashMessage('', $message, Message::SEVERITY_OK);
                $this->info('JobPosting with URL: ' . $nodeUriToUpdate . ' updated...');
            } catch (Google_Service_Exception $error) {
                $message = $this->translateById('error.googleServiceException');
                $this->addFlashMessage('', $message . " \r\n " . $error->getMessage() , Message::SEVERITY_ERROR);
                $this->info('Error: ' . $message . " \r\n - Status code: " . $error->getCode() . ' - Message: ' . $error->getMessage());
            }
        }

        if ($this->logGoogleClientConfiguration) {
            $this->info('Client configuration');
            $this->info('application_name: ' . $this->jobIndexingService->getClient()->getConfig('application_name'));
            $this->info('base_path: ' . $this->jobIndexingService->getClient()->getConfig('base_path'));
            $this->info('client_id: ' . $this->jobIndexingService->getClient()->getConfig('client_id'));
            $this->info('use_application_default_credentials: ' . $this->jobIndexingService->getClient()->getConfig('use_application_default_credentials'));
            $this->info('signing_algorithm: ' . $this->jobIndexingService->getClient()->getConfig('signing_algorithm'));
            $this->info('api_format_v2: ' . $this->jobIndexingService->getClient()->getConfig('api_format_v2'));
            $this->info('OAuth2Service');
            $this->info('ClientId: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getClientId());
            $this->info('ClientSecret: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getClientSecret());
            $this->info('AuthorizationUri: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getAuthorizationUri());
            $this->info('TokenCrentialUri: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getTokenCredentialUri());
            $this->info('RedirectUri: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getRedirectUri());
            $this->info('Issuer: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getIssuer());
            $this->info('SigningKey: ' . $this->jobIndexingService->getClient()->getOAuth2Service()->getSigningKey());
        }

        $redirectArguments = [
            'siteNodeName' => $siteNodeName,
            'siteDimension' => $siteDimension
        ];

        $this->redirect('showJobPostingsForSite', null, null, $redirectArguments);
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
     * Shorthand to translate labels for this package
     *
     * @param string|null $id
     * @param array $arguments
     * @return string
     */
    protected function translateById(string $id, array $arguments = []): ?string
    {
        return $this->translator->translateById($id, $arguments, null, null, 'BackendModule',
            'TechDivision.Jobs.GoogleApi');
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

    /**
     * Logs with an arbitrary level.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = array()) {
        $this->jobIndexingLogger->log($level, $message, $context);
    }
}
