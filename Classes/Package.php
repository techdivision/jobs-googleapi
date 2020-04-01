<?php
declare(strict_types=1);
namespace TechDivision\Jobs\GoogleApi;

/*
* This file is part of the TechDivision.Job package.
*
* TechDivision - neos@techdivision.com
*
* This package is Open Source Software. For the full copyright and license
* information, please view the LICENSE file which was distributed with this
* source code.
*/

use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\ContentRepository\Domain\Model\Workspace;
use TechDivision\Jobs\GoogleApi\Service\JobPublishingService;

/**
 * Class Package
 * @package TechDivision\Jobs\GoogleApi
 */
class Package extends BasePackage
{

    /**
     * @Flow\InjectConfiguration(path="enableAutomaticDeletion")
     * @var boolean
     */
    protected $enableApiCallOnJobDeletion;

    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        if (isset($enableApiCallOnJobDeletion) && $enableApiCallOnJobDeletion === true) {
            $dispatcher = $bootstrap->getSignalSlotDispatcher();
            $dispatcher->connect(Workspace::class, 'beforeNodePublishing', JobPublishingService::class, 'collectPossibleJobPostingAndPublish');
            $dispatcher->connect(PersistenceManager::class, 'allObjectsPersisted', JobPublishingService::class, 'publishCrawlingQueue');
        }
    }
}