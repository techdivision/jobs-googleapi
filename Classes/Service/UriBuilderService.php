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

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Exception;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Response;
use Neos\Flow\Http\Uri;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder as FlowMvcUriBuilder;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Service\LinkingService;
use Neos\Neos\Exception as NeosException;
use TechDivision\Jobs\GoogleApi\Exceptions\NoContextException;

/**
 * Uri builder for creating uris
 * @Flow\Scope("singleton")
 */
class UriBuilderService {

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var FlowMvcUriBuilder
     */
    protected $flowMvcUriBuilder;

    /**
     * @var string
     */
    protected $currentDocumentUri = null;

    /**
     * @var ControllerContext
     */
    protected $controllerContext;

    /**
     * @var Bootstrap
     * @Flow\Inject
     */
    protected $bootstrap;

    /**
     * The uri before path is used for the export xml file for the page uris. If nothing is set, tries to use current environment.
     * This uri must match a configured site object in neos, otherwise the default primary site will be used
     * @var string
     */
    protected $uriBeforePath;

    /**
     * Builds an uri for given node data
     * @param NodeData $nodeData
     * @return bool
     * @throws NodeTypeNotFoundException
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws NoContextException
     */
    public function buildUriForNodeData(NodeData $nodeData) {
        // if nodeData is no document node use current document uri(from the last run)
        if(!$nodeData->getNodeType()->isOfType("Neos.Neos:Document")) {
            return false;
        }

        $node = $this->getNode($nodeData);

        if(!$node) {
            return false;
        }

        // can fail because sometimes there are tree nodes in a broken tree, this ignores them
        try {
            $this->currentDocumentUri = $this->linkingService->createNodeUri(
                $this->getControllerContext(),
                $node,
                $node->getContext()->getRootNode(),
                'html',
                true
            );
        }
        catch (NeosException $exception){}
        catch (\InvalidArgumentException $exception) {}

        return true;
    }

    /**
     * Gets the last generated uri
     * @return string
     */
    public function getLastUri() {
        return $this->currentDocumentUri;
    }

    /**
     * @param NodeData $nodeData
     * @return NodeInterface|null
     */
    protected function getNode(NodeData $nodeData) {

        // create live context
        $context = $this->contextFactory->create(['workspaceName' => 'live']);

        // try to retrieve node
        try {
            return $this->nodeFactory->createFromNodeData($nodeData, $context);
        }catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return string
     */
    public function getUriBeforePath()
    {
        return $this->uriBeforePath;
    }

    /**
     * @param string $uriBeforePath
     */
    public function setUriBeforePath($uriBeforePath)
    {
        $this->uriBeforePath = $uriBeforePath;
    }

    /**
     * Gets a controller context
     * @return ControllerContext
     * @throws NoContextException
     */
    protected function getControllerContext() {

        if($this->controllerContext) {
            return $this->controllerContext;
        }

        $request = null;

        if($this->uriBeforePath) {
            $request = Request::create(new Uri($this->uriBeforePath));
            // needed to avoid appearing index.php in the uri path
            putenv('FLOW_REWRITEURLS=true');
        } else if(PHP_SAPI === 'cli') {
            throw new NoContextException("The UriBuilder needs as context the uriBeforePath on cli");
        } else {
            $requestHandler = $this->bootstrap->getActiveRequestHandler();
            $request = $requestHandler->getHttpRequest();
        }

        // try building an action request
        try {
            $actionReqeust = new ActionRequest($request);
            $actionReqeust->setControllerPackageKey("Neos.Neos");
            $actionReqeust->setControllerName(" Neos\Neos\Controller\Frontend\NodeController");
            $actionReqeust->setControllerActionName("show");
            $actionReqeust->setFormat("html");
            $this->flowMvcUriBuilder->setRequest($actionReqeust);
        }catch (\Exception $e){
            return;
        }

        $this->controllerContext =  new ControllerContext(
            $actionReqeust,
            new Response(),
            new Arguments(array()),
            $this->flowMvcUriBuilder
        );

        return $this->controllerContext;
    }
}
