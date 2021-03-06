<?php

declare(strict_types=1);

/*
 * This file is part of the IOTA PHP package.
 *
 * (c) Benjamin Ansbach <benjaminansbach@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IOTA\RemoteApi\Actions\FindTransactions;

use IOTA\RemoteApi\AbstractAction;
use IOTA\RemoteApi\AbstractResult;
use IOTA\RemoteApi\Exception;
use IOTA\Type\Address;
use IOTA\Type\Approvee;
use IOTA\Type\BundleHash;
use IOTA\Type\Tag;
use IOTA\Util\SerializeUtil;

/**
 * Class Action.
 *
 * Searches for transaction hashes that match the specified bundle-hashes,
 * addresses, tags or approvees. Using multiple of these parameters returns
 * the intersection of the values.
 *
 * @see https://iota.readme.io/docs/findtransactions
 */
class Action extends AbstractAction
{
    /**
     * List of bundle hashes.
     *
     * @var BundleHash[]
     */
    protected $bundleHashes;

    /**
     * List of addresses.
     *
     * @var Address[]
     */
    protected $addresses;

    /**
     * List of tags.
     *
     * @var Tag[]
     */
    protected $tags;

    /**
     * List of approvee transaction hashes.
     *
     * @var Approvee[]
     */
    protected $approvees;

    /**
     * Sets all bundles hashes.
     *
     * @param BundleHash[] $bundleHashes
     *
     * @return Action
     */
    public function setBundleHashes(array $bundleHashes): self
    {
        $this->bundleHashes = [];
        foreach ($bundleHashes as $bundle) {
            $this->addBundleHash($bundle);
        }

        return $this;
    }

    /**
     * Adds a bundle hash.
     *
     * @param BundleHash $bundle
     *
     * @return Action
     */
    public function addBundleHash(BundleHash $bundle): self
    {
        $this->bundleHashes[] = $bundle;

        return $this;
    }

    /**
     * Gets the list of bundle hashes.
     *
     * @return BundleHash[]
     */
    public function getBundleHashes(): array
    {
        return $this->bundleHashes;
    }

    /**
     * Sets all addresses.
     *
     * @param Address[] $addresses
     *
     * @return Action
     */
    public function setAddresses(array $addresses): self
    {
        $this->addresses = [];
        foreach ($addresses as $address) {
            $this->addAddress($address);
        }

        return $this;
    }

    /**
     * Adds a single address.
     *
     * @param Address $address
     *
     * @return Action
     */
    public function addAddress(Address $address): self
    {
        $this->addresses[] = $address;

        return $this;
    }

    /**
     * Gets the list of addresses.
     *
     * @return Address[]
     */
    public function getAddresses(): array
    {
        return $this->addresses;
    }

    /**
     * Sets the tags.
     *
     * @param Tag[] $tags
     *
     * @return Action
     */
    public function setTags(array $tags): self
    {
        $this->tags = [];
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }

        return $this;
    }

    /**
     * Adds a single tag.
     *
     * @param Tag $tag
     *
     * @return Action
     */
    public function addTag(Tag $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    /**
     * Gets the list of tags.
     *
     * @return Tag[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Sets the list of approvees.
     *
     * @param Approvee[] $approvees
     *
     * @return Action
     */
    public function setApprovees(array $approvees): self
    {
        $this->approvees = [];
        foreach ($approvees as $approvee) {
            $this->addApprovee($approvee);
        }

        return $this;
    }

    /**
     * Adds a single approvee.
     *
     * @param Approvee $approvee
     *
     * @return Action
     */
    public function addApprovee(Approvee $approvee): self
    {
        $this->approvees[] = $approvee;

        return $this;
    }

    /**
     * Gets the list of approvees.
     *
     * @return Approvee[]
     */
    public function getApprovees(): array
    {
        return $this->approvees;
    }

    /**
     * Gets the data that should be sent to the nodes endpoint.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        $params = [
            'command' => 'findTransactions',
        ];

        if (\count($this->bundleHashes) > 0) {
            $params['bundles'] = array_map('\strval', $this->bundleHashes);
        }

        if (\count($this->addresses) > 0) {
            $params['addresses'] = array_map(function (Address $address) {
                return (string) $address->removeChecksum();
            }, $this->addresses);
        }

        if (\count($this->tags) > 0) {
            $params['tags'] = array_map('\strval', $this->tags);
        }

        if (\count($this->approvees) > 0) {
            $params['approvees'] = array_map('\strval', $this->approvees);
        }

        return $params;
    }

    /**
     * Executes the request.
     *
     * @throws Exception
     *
     * @return AbstractResult|Result
     */
    public function execute(): Result
    {
        $response = new Result($this);
        $srvResponse = $this->nodeApiClient->send($this);
        $response->initialize($srvResponse['code'], $srvResponse['raw']);

        return $response->finish()->throwOnError();
    }

    public function serialize(): array
    {
        return array_merge(parent::serialize(), [
            'bundleHashes' => SerializeUtil::serializeArray($this->bundleHashes),
            'addresses' => SerializeUtil::serializeArray($this->addresses),
            'tags' => SerializeUtil::serializeArray($this->tags),
            'approvees' => SerializeUtil::serializeArray($this->approvees),
        ]);
    }
}
