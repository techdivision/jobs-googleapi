<?php
namespace TechDivision\Jobs\GoogleApi\Service;

/**
 * This file is part of the TechDivision.Jobs.GoogleApi package.
 *
 * TechDivision - neos@techdivision.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use DateTime;
use Google_Service_Indexing_UrlNotification;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Http\Request;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Service\LinkingService;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use TechDivision\Jobs\GoogleApi\Exceptions\PublishingException;

/**
 * Google publishing service
 * @Flow\Scope("singleton")
 */
class JobPublishingService
{
    use CreateContentContextTrait;
    use LoggerTrait;

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $logger;

    /**
     * @var array
     */
    protected $pendingJobPostings = [];

    /**
     * @var JobIndexingService
     * @Flow\Inject
     */
    protected $jobIndexingService;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @param string $jobPostingUri
     * @return \Google_Service_Indexing_PublishUrlNotificationResponse
     * @throws \Exception
     */
    public function updateJobPosting(string $jobPostingUri) {
        $urlNotification = new Google_Service_Indexing_UrlNotification();
        $today = new DateTime();
        $urlNotification->setNotifyTime($today->format(DateTime::RFC3339));
        $urlNotification->setUrl($jobPostingUri);
        $urlNotification->setType('URL_UPDATED');
        return $this->jobIndexingService->getUrlNotifications()->publish($urlNotification);
    }

    /**
     * @param string $jobPostingUri
     * @return \Google_Service_Indexing_PublishUrlNotificationResponse
     * @throws \Exception
     */
    public function deleteJobPosting(string $jobPostingUri) {
        $urlNotification = new Google_Service_Indexing_UrlNotification();
        $today = new DateTime();
        $urlNotification->setNotifyTime($today->format(DateTime::RFC3339));
        $urlNotification->setUrl($jobPostingUri);
        $urlNotification->setType('URL_DELETED');
        return $this->jobIndexingService->getUrlNotifications()->publish($urlNotification);
    }

    /**
     * @return ControllerContext
     */
    protected function createControllerContextFromEnvironment()
    {
        $httpRequest = Request::createFromEnvironment();

        /** @var ActionRequest $request */
        $request = new ActionRequest($httpRequest);

        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function collectPossibleJobPostingAndPublish(NodeInterface $node, Workspace $targetWorkspace): void
    {
        if ($targetWorkspace->isPublicWorkspace() !== true) {
            $this->info("Is in public workspace, no publishing");
            return;
        }

        if($node->getNodeType()->isOfType('TechDivision.Jobs:Document.JobPosting') === true && $node->isRemoved()) {
            $controllerContext = $this->createControllerContextFromEnvironment();
            $context = $this->_contextFactory->create([
                'workspaceName' => 'live',
                'dimensions' => $node->getDimensions()
            ]);
            $liveNode = $context->getNodeByIdentifier($node->getIdentifier());
            $uri = $this->linkingService->createNodeUri($controllerContext, $liveNode, null, null, true);
            $this->info("Add node to crawling queue");
            $this->addNodeToCrawlingQueue($uri);
        }
        return;
    }

    /**
     * @param $uri
     */
    protected function addNodeToCrawlingQueue($uri) {
        $this->info("Adding nodeURI to pending jobPostings -> " . $uri);
        array_push($this->pendingJobPostings, $uri);
    }

    /**
     * @throws PublishingException
     */
    public function publishCrawlingQueue() {
        foreach ($this->pendingJobPostings as $removedJobPostingUri) {
            try {
                $this->info("Update JobPosting -> " . $removedJobPostingUri);
                $this->deleteJobPosting($removedJobPostingUri);
            } catch (\Exception $error) {
                $this->error("An error occurred while publishing: " . $error);
                throw new PublishingException("An error occurred while publishing: " . $error);
            }
        }
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = array()) {
        $this->logger->log($level, $message, $context);
    }
}
