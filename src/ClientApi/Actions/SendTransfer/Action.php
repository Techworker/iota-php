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

namespace IOTA\ClientApi\Actions\SendTransfer;

use IOTA\ClientApi\AbstractAction;
use IOTA\ClientApi\Actions\GetInputs;
use IOTA\ClientApi\Actions\GetNewAddress;
use IOTA\ClientApi\Actions\SendTrytes;
use IOTA\Cryptography\Hashing\CurlFactory;
use IOTA\Cryptography\Hashing\KerlFactory;
use IOTA\Cryptography\HMAC;
use IOTA\Cryptography\Signing;
use IOTA\Exception;
use IOTA\Node;
use IOTA\RemoteApi\Actions\GetBalances;
use IOTA\Type\Address;
use IOTA\Type\Bundle;
use IOTA\Type\HMACKey;
use IOTA\Type\Input;
use IOTA\Type\Iota;
use IOTA\Type\Milestone;
use IOTA\Type\SecurityLevel;
use IOTA\Type\Seed;
use IOTA\Type\SignatureMessageFragment;
use IOTA\Type\Tag;
use IOTA\Type\Transaction;
use IOTA\Type\Transfer;
use IOTA\Type\Trytes;
use IOTA\Util\SerializeUtil;
use IOTA\Util\TritsUtil;
use IOTA\Util\TrytesUtil;

/**
 * Class Action.
 */
class Action extends AbstractAction
{
    use GetNewAddress\ActionTrait,
        GetInputs\ActionTrait,
        SendTrytes\ActionTrait,
        GetBalances\ActionTrait;

    /**
     * The seed used to generate addresses.
     *
     * @var Seed
     */
    protected $seed;

    /**
     * The depth for the search of transactions to approve.
     *
     * @var int
     */
    protected $depth;

    /**
     * The difficulty.
     *
     * @var int
     */
    protected $minWeightMagnitude;

    /**
     * The list of transfers to create transactions from.
     *
     * @var Transfer[]
     */
    protected $transfers;

    /**
     * The list of inputs used to fund the transactions.
     *
     * @var Input[]
     */
    protected $inputs = [];

    /**
     * The security level.
     *
     * @var SecurityLevel
     */
    protected $security;

    /**
     * The HMAC key to add to the transaction signature.
     *
     * @var HMACKey
     */
    protected $hmacKey;

    /**
     * The remainder address where left over funds from the inputs will be sent
     * to.
     *
     * @var Address
     */
    protected $remainderAddress;

    /**
     * The total value of all transfers.
     *
     * @var Iota
     */
    protected $totalValue;

    /**
     * The bundle generated by the action.
     *
     * @var Bundle
     */
    protected $bundle;

    /**
     * Tag used in the transactions.
     *
     * @var Tag
     */
    protected $tag;

    /**
     * List of compiled signature message fragments.
     *
     * @var SignatureMessageFragment[]
     */
    protected $signatureFragments;

    /**
     * Factory to create the kerl instance.
     *
     * @var KerlFactory
     */
    protected $kerlFactory;

    /**
     * Factory to create the curl instance.
     *
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * Factory to create the hmac instance to add HMAC.
     *
     * @var callable
     */
    protected $hmacFactory;

    /**
     * The reference transaction for the getTransactionsToApprove.
     *
     * @var Milestone
     */
    protected $reference;

    /**
     * Action constructor.
     *
     * @param Node                        $node
     * @param GetNewAddress\ActionFactory $getNewAddressFactory
     * @param GetInputs\ActionFactory     $getInputsFactory
     * @param GetBalances\ActionFactory   $getBalancesFactory
     * @param SendTrytes\ActionFactory    $sendTrytesFactory
     * @param KerlFactory                 $kerlFactory
     * @param CurlFactory                 $curlFactory
     * @param callable                    $hmacFactory
     */
    public function __construct(
        Node $node,
        GetNewAddress\ActionFactory $getNewAddressFactory,
        GetInputs\ActionFactory $getInputsFactory,
        GetBalances\ActionFactory $getBalancesFactory,
        SendTrytes\ActionFactory $sendTrytesFactory,
        KerlFactory $kerlFactory,
        CurlFactory $curlFactory,
        callable $hmacFactory
    ) {
        parent::__construct($node);

        $this->setGetInputsFactory($getInputsFactory);
        $this->setSendTrytesFactory($sendTrytesFactory);

        $this->setGetNewAddressFactory($getNewAddressFactory);
        $this->setGetBalancesFactory($getBalancesFactory);
        $this->kerlFactory = $kerlFactory;
        $this->curlFactory = $curlFactory;
        $this->hmacFactory = $hmacFactory;

        // Create a new bundle
        $this->bundle = new Bundle($kerlFactory, $curlFactory);

        $this->totalValue = Iota::ZERO();
        $this->signatureFragments = [];
    }

    /**
     * The seed from which the transfer is sent.
     *
     * @param Seed $seed
     *
     * @return Action
     */
    public function setSeed(Seed $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * The depth.
     *
     * @param int $depth
     *
     * @return Action
     */
    public function setDepth(int $depth): self
    {
        $this->depth = $depth;

        return $this;
    }

    /**
     * @param int $minWeightMagnitude
     *
     * @return Action
     */
    public function setMinWeightMagnitude(int $minWeightMagnitude): self
    {
        $this->minWeightMagnitude = $minWeightMagnitude;

        return $this;
    }

    /**
     * @param Transfer[] $transfers
     *
     * @return Action
     */
    public function setTransfers(array $transfers): self
    {
        $this->transfers = [];
        foreach ($transfers as $transfer) {
            $this->addTransfer($transfer);
        }

        return $this;
    }

    /**
     * Adds a single transfer.
     *
     * @param Transfer $transfer
     *
     * @return Action
     */
    public function addTransfer(Transfer $transfer): self
    {
        $this->transfers[] = $transfer;

        return $this;
    }

    /**
     * @param Input[] $inputs
     *
     * @return Action
     */
    public function setInputs(array $inputs): self
    {
        $this->inputs = [];
        foreach ($inputs as $input) {
            $this->addInput($input);
        }

        return $this;
    }

    /**
     * Adds a single input.
     *
     * @param Input $input
     *
     * @return Action
     */
    public function addInput(Input $input): self
    {
        $this->inputs[] = $input;

        return $this;
    }

    /**
     * @param SecurityLevel $security
     *
     * @return Action
     */
    public function setSecurity(SecurityLevel $security): self
    {
        $this->security = $security;

        return $this;
    }

    /**
     * @param HMACKey $hmacKey
     *
     * @return Action
     */
    public function setHmacKey(HMACKey $hmacKey): self
    {
        $this->hmacKey = $hmacKey;

        return $this;
    }

    /**
     * Sets the reference transaction.
     *
     * @param Milestone $reference
     *
     * @return Action
     */
    public function setReference(Milestone $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * @param Address $remainderAddress
     *
     * @return Action
     */
    public function setRemainderAddress(Address $remainderAddress): self
    {
        $this->remainderAddress = $remainderAddress;

        return $this;
    }

    /**
     * Executes the action.
     */
    public function execute(): Result
    {
        $result = new Result($this);
        $this->prepareTransfers();
        $this->createTransactions();

        $transactions = [];
        foreach ($this->bundle->getTransactions() as $transaction) {
            $transactions[] = new Transaction($this->curlFactory, (string)$transaction);
        }
        $trytesResult = $this->sendTrytes(
            $this->node,
            array_reverse($transactions),
            $this->minWeightMagnitude,
            $this->depth,
            $this->reference
        );

        $result->addChildTrace($trytesResult->getTrace());
        $result->setTrunkTransactionHash($trytesResult->getTrunkTransactionHash());
        $result->setBranchTransactionHash($trytesResult->getBranchTransactionHash());
        $bundle = new Bundle($this->kerlFactory, $this->curlFactory);
        foreach ($trytesResult->getTransactions() as $transaction) {
            $bundle->addTransaction($transaction);
            $bundle->setBundleHash($transaction->getBundleHash());
        }

        $result->setBundle($bundle);
        $result->finish();

        return $result;
    }

    public function serialize(): array
    {
        return array_merge(
            parent::serialize(),
            [
                'depth' => $this->depth,
                'minWeightMagnitude' => $this->minWeightMagnitude,
                'transfers' => SerializeUtil::serializeArray($this->transfers),
                'inputs' => SerializeUtil::serializeArray($this->inputs),
                'security' => $this->security->serialize(),
                'hmacKey' => null === $this->hmacKey ? null : $this->hmacKey->serialize(),
                'remainderAddress' => $this->remainderAddress->serialize(),
                'reference' => null === $this->reference ? null : $this->reference->serialize(),
            ]
        ); // TODO: Change the autogenerated stub
    }

    /**
     * Creates all necessary transactions for the given transfers.
     */
    protected function createTransactions(): void
    {
        foreach ($this->transfers as $transfer) {
            $signatureMessageLength = 1;

            // If message longer than 2187 trytes, increase
            // signatureMessageLength (add 2nd transaction)
            if ($transfer->getMessage()->count() > 2187) {
                // Get total length, message / maxLength (2187 trytes)
                $signatureMessageLength += floor($transfer->getMessage()->count() / 2187);

                $messageCopy = (string)$transfer->getMessage();

                // While there is still a message, copy it
                while (\strlen($messageCopy) > 0) {
                    $fragment = substr($messageCopy, 0, 2187);
                    $messageCopy = substr($messageCopy, 2187, \strlen($messageCopy));
                    $fragment = str_pad($fragment, 2187, '9');

                    $this->signatureFragments[] = new SignatureMessageFragment($fragment);
                }
            } else {
                // Else, get single fragment with 2187 of 9's trytes
                $fragment = substr((string)$transfer->getMessage(), 0, 2187);
                $fragment = str_pad($fragment, 2187, '9');
                $this->signatureFragments[] = new SignatureMessageFragment($fragment);
            }

            $timestamp = time();
            $this->tag = $transfer->getObsoleteTag();

            // Add first entries to the bundle
            $this->bundle->addNewTransaction(
                $signatureMessageLength,
                $transfer->getRecipientAddress(),
                $transfer->getValue(),
                $this->tag,
                $timestamp
            );

            // Sum up total value
            $this->totalValue = $this->totalValue->plus($transfer->getValue());
        }

        // if the value > 0 we'll have to collect the addresses from where
        // to get the funds
        if ($this->totalValue->isPos()) {
            /** @var Input[] $inputs */
            $inputs = [];

            $totalBalance = Iota::ZERO();
            // user provided the inputs? cool!
            if (\count($this->inputs) > 0) {
                // loop all addresses and collect the balances until we hit
                // the required value
                $inputsAddresses = [];
                foreach ($this->inputs as $input) {
                    $inputsAddresses[] = $input->getAddress();
                }

                // fetch balances of all inputs and sum them up
                $balances = $this->getBalances($this->node, $inputsAddresses);

                foreach ($balances->getBalances() as $i => $balance) {
                    if (!$balance->isPos()) {
                        continue;
                    }
                    $totalBalance = $totalBalance->plus($balance);
                    $input = $this->inputs[$i];
                    $input->setBalance($balance);
                    $inputs[] = $input;

                    // collected enough? then all is fine and we can go on.
                    if ($totalBalance->gteq($this->totalValue)) {
                        break;
                    }
                }
            } else {
                // fetch all inputs from remote
                $inputs = $this->getInputs(
                    $this->node,
                    $this->seed,
                    0,
                    -1,
                    $this->totalValue,
                    $this->security
                )->getInputs();

                foreach ($inputs as $input) {
                    $totalBalance = $totalBalance->plus($input->getBalance());
                }
            }

            // not enough funds in provided or found addresses
            if ($this->totalValue->gt($totalBalance)) {
                // TODO: custom exception?
                throw new Exception('Not enough balance');
            }

            $this->addRemainder($inputs);

            return;
        }

        // If no input required, don't sign and simply finalize the bundle
        $this->bundle->finalize();
        $this->bundle->addSignatureMessageFragments($this->signatureFragments);
    }

    /**
     * Loops the transfers and handles hmac preparation and removes the checksum
     * from the given addresses.
     */
    protected function prepareTransfers()
    {
        foreach ($this->transfers as $transfer) {
            // if an hmac key is given,
            if (null !== $this->hmacKey && $transfer->getValue()->gt(new Iota(0))) {
                // TODO: Trytes method prepend, append?
                $transfer->setMessage(
                    new Trytes(
                        (string)TrytesUtil::nullHashTrytes().
                        (string)$transfer->getMessage()
                    )
                );
            }

            // remove the checksum
            /** @var Address $recipientAddress */
            $recipientAddress = $transfer->getRecipientAddress()->removeChecksum();
            $transfer->setRecipientAddress($recipientAddress);
        }
    }

    /**
     * Adds remainder transaction in case there was more balance on the inputs
     * than needed.
     *
     * @param Input[] $inputs
     */
    protected function addRemainder(array $inputs): void
    {
        // copy
        $totalTransferValue = $this->totalValue->plus(Iota::ZERO());

        // loop the inputs
        foreach ($inputs as $input) {
            $timestamp = time();

            // Add input as bundle entry
            $this->bundle->addNewTransaction(
                $input->getSecurity()->getLevel(),
                $input->getAddress(),
                Iota::ZERO()->minus($input->getBalance()),
                $this->tag,
                $timestamp
            );

            // If there is a remainder value
            // Add extra output to send remaining funds to
            if ($input->getBalance()->gteq($totalTransferValue)) {
                $remainder = $input->getBalance()->minus($totalTransferValue);

                // If user has provided remainder address
                // Use it to send remaining funds to
                if (null !== $this->remainderAddress && $remainder->isPos()) {
                    // Remainder bundle entry
                    $this->bundle->addNewTransaction(
                        1,
                        $this->remainderAddress,
                        $remainder,
                        $this->tag,
                        $timestamp
                    );

                    // Final function for signing inputs
                    $this->signTransactions($inputs);

                    return;
                }
                if ($remainder->isPos()) {
                    // generate a new address
                    $addRes = $this->getNewAddress(
                        $this->node,
                        $this->seed,
                        $this->getMaxInputAddressIndex($inputs),
                        false,
                        $this->security
                    );
                    $this->remainderAddress = $addRes->getAddress();

                    $timestamp = time();
                    $this->bundle->addNewTransaction(
                        1,
                        $addRes->getAddress(),
                        $remainder,
                        $this->tag,
                        $timestamp
                    );

                    // Final function for signing inputs
                    $this->signTransactions($inputs);

                    return;
                }
                $this->signTransactions($inputs);

                return;
            }
            $totalTransferValue = $totalTransferValue->minus($input->getBalance());
        }
    }

    /**
     * Tries to determine the maximum address index of the available inputs.
     *
     * @param Input[] $inputs
     *
     * @return int
     */
    protected function getMaxInputAddressIndex(array $inputs): int
    {
        $maxIndex = 0;
        foreach ($inputs as $input) {
            if ($input->getAddress()->getIndex() > $maxIndex) {
                $maxIndex = $input->getAddress()->getIndex();
            }
        }

        return $maxIndex;
    }

    /**
     * Signs the transactions.
     *
     * @param array $inputs
     */
    protected function signTransactions(array $inputs): void
    {
        $this->bundle->finalize();
        $this->bundle->addSignatureMessageFragments($this->signatureFragments);

        //  SIGNING OF INPUTS
        //
        //  Here we do the actual signing of the inputs
        //  Iterate over all bundle transactions, find the inputs
        //  Get the corresponding private key and calculate the signatureFragment
        $transactions = $this->bundle->getTransactions();
        foreach ($transactions as $idx => $transaction) {
            if ($transaction->getValue()->isNeg()) {
                // Get the corresponding keyIndex and security of the address
                $keyIndex = 0;
                $keySecurity = SecurityLevel::LEVEL_2();
                foreach ($inputs as $input) {
                    if ((string)$input->getAddress()->removeChecksum() ===
                        (string)$transaction->getAddress()->removeChecksum()
                    ) {
                        $keyIndex = $input->getIndex();
                        $keySecurity = $input->getSecurity() ?? $this->security;

                        break;
                    }
                }

                $key = Signing::key($this->kerlFactory, $this->seed, $keyIndex, $keySecurity);

                $normalizedBundleHash = $this->bundle->getBundleHash()->normalized();
                $normalizedBundleFragments = [];

                // Split hash into 3 fragments
                for ($l = 0; $l < 3; ++$l) {
                    $normalizedBundleFragments[$l] = \array_slice($normalizedBundleHash, $l * 27, ($l + 1) * 27);
                }

                //  First 6561 trits for the firstFragment
                $firstFragment = \array_slice($key, 0, 6561);

                //  First bundle fragment uses the first 27 trytes
                $firstBundleFragment = $normalizedBundleFragments[0];

                //  Calculate the new signatureFragment with the first bundle fragment
                $firstSignedFragment = Signing::signatureFragment(
                    $this->kerlFactory,
                    $firstBundleFragment,
                    $firstFragment
                );

                //  Convert signature to trytes and assign the new signatureFragment

                $transaction->setSignatureMessageFragment(
                    new SignatureMessageFragment((string)TritsUtil::toTrytes($firstSignedFragment))
                );

                // if user chooses higher than 27-tryte security
                // for each security level, add an additional signature
                for ($j = 1; $j < $keySecurity->getLevel(); ++$j) {
                    //  Because the signature is > 2187 trytes, we need to
                    //  find the subsequent transaction to add the remainder of the signature
                    //  Same address as well as value = 0 (as we already spent the input)
                    if ((string)$this->bundle->getTransactions()[$idx + $j]->getAddress() ===
                        (string)$transaction->getAddress() &&
                        $this->bundle->getTransactions()[$idx + $j]->getValue()->isZero()
                    ) {
                        // Use the next 6561 trits
                        $nextFragment = \array_slice($key, 6561 * $j, ($j + 1) * 6561);

                        $nextBundleFragment = $normalizedBundleFragments[$j];

                        //  Calculate the new signature
                        $nextSignedFragment = Signing::signatureFragment(
                            $this->kerlFactory,
                            $nextBundleFragment,
                            $nextFragment
                        );

                        //  Convert signature to trytes and assign it again to this bundle entry
                        $this->bundle->getTransactions()[$idx + $j]->setSignatureMessageFragment(
                            new SignatureMessageFragment((string)TritsUtil::toTrytes($nextSignedFragment))
                        );
                    }
                }
            }
        }

        if (null !== $this->hmacKey) {
            /** @var $hmac HMAC */
            $hmac = ($this->hmacFactory)($this->hmacKey);
            $hmac->addHMAC($this->bundle);
        }
    }
}
