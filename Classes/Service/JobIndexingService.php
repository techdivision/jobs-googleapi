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

use Google_Client;
use Neos\Flow\Annotations as Flow;

/**
 *
 * @Flow\Scope("singleton")
 */
class JobIndexingService extends \Google_Service_Indexing
{
    /**
     * @inheritdoc
     */
    public function __construct(Google_Client $client)
    {
        parent::__construct($client);
        $client->addScope(\Google_Service_Indexing::INDEXING);
    }

    /**
     * @return \Google_Service_Indexing_Resource_UrlNotifications
     */
    public function getUrlNotifications() {
        return $this->urlNotifications;
    }
}
