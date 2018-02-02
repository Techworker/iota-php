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

namespace IOTA\ClientApi\Actions\GetAddresses;

use IOTA\ClientApi\AbstractResult;
use IOTA\Type\Address;
use IOTA\Util\SerializeUtil;

class Result extends AbstractResult
{
    /**
     * The list of addresses indexed by its index position.
     *
     * @var Address[]
     */
    protected $addresses = [];

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
     * Adds an address at the given index.
     *
     * @param Address $address
     * @param int     $index
     *
     * @return Result
     */
    public function addAddress(Address $address, int $index): self
    {
        $this->addresses[$index] = $address;

        return $this;
    }

    /**
     * Gets the serialized version of the result.
     *
     * @return array
     */
    public function serialize(): array
    {
        return array_merge([
            'addresses' => SerializeUtil::serializeArray($this->addresses),
        ], parent::serialize());
    }
}
