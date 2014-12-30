<?php
/**
 * @copyright Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 */

namespace Magento\Integration\Model\Oauth\Token;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Oauth\TokenProviderInterface;
use Magento\Integration\Model\Oauth\Token;

class Provider implements TokenProviderInterface
{
    /**
     * @var \Magento\Integration\Model\Oauth\Consumer\Factory
     */
    protected $_consumerFactory;

    /**
     * @var \Magento\Integration\Model\Oauth\Token\Factory
     */
    protected $_tokenFactory;

    /**
     * @var  \Magento\Integration\Helper\Oauth\Data
     */
    protected $_dataHelper;

    /**
     * @var Token
     */
    protected $token;

    /**
     * @param \Magento\Integration\Model\Oauth\Consumer\Factory $consumerFactory
     * @param \Magento\Integration\Model\Oauth\Token\Factory $tokenFactory
     * @param \Magento\Integration\Helper\Oauth\Data $dataHelper
     * @param Token $token
     */
    public function __construct(
        \Magento\Integration\Model\Oauth\Consumer\Factory $consumerFactory,
        \Magento\Integration\Model\Oauth\Token\Factory $tokenFactory,
        \Magento\Integration\Helper\Oauth\Data $dataHelper,
        Token $token
    ) {
        $this->_consumerFactory = $consumerFactory;
        $this->_tokenFactory = $tokenFactory;
        $this->_dataHelper = $dataHelper;
        $this->token = $token;
    }

    /**
     * {@inheritdoc}
     */
    public function validateConsumer($consumer)
    {
        $expiry = $this->_dataHelper->getConsumerExpirationPeriod();
        // Must use consumer within expiration period.
        if ($this->_consumerFactory->create()->getTimeInSecondsSinceCreation($consumer->getId()) > $expiry) {
            throw new \Magento\Framework\Oauth\Exception(
                'Consumer key has expired'
            );
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createRequestToken($consumer)
    {
        $token = $this->getIntegrationTokenByConsumerId($consumer->getId());
        if ($token->getType() != Token::TYPE_VERIFIER) {
            throw new \Magento\Framework\Oauth\Exception(
                'Cannot create request token because consumer token is not a verifier token'
            );
        }
        $requestToken = $token->createRequestToken($token->getId(), $consumer->getCallbackUrl());
        return ['oauth_token' => $requestToken->getToken(), 'oauth_token_secret' => $requestToken->getSecret()];
    }

    /**
     * {@inheritdoc}
     */
    public function validateRequestToken($requestToken, $consumer, $oauthVerifier)
    {
        $token = $this->_getToken($requestToken);

        if (!$this->_isTokenAssociatedToConsumer($token, $consumer)) {
            throw new \Magento\Framework\Oauth\Exception(
                'Request token is not associated with the specified consumer'
            );
        }

        // The pre-auth token has a value of "request" in the type when it is requested and created initially.
        // In this flow (token flow) the token has to be of type "request" else its marked as reused.
        if (Token::TYPE_REQUEST != $token->getType()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token is already being used'
            );
        }

        $this->_validateVerifierParam($oauthVerifier, $token->getVerifier());

        return $token->getSecret();
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken($consumer)
    {
        /** TODO: log the request token in dev mode since its not persisted. */
        $token = $this->getIntegrationTokenByConsumerId($consumer->getId());
        if (Token::TYPE_REQUEST != $token->getType()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Cannot get access token because consumer token is not a request token'
            );
        }
        $accessToken = $token->convertToAccess();
        return ['oauth_token' => $accessToken->getToken(), 'oauth_token_secret' => $accessToken->getSecret()];
    }

    /**
     * {@inheritdoc}
     */
    public function validateAccessTokenRequest($accessToken, $consumer)
    {
        $token = $this->_getToken($accessToken);

        if (!$this->_isTokenAssociatedToConsumer($token, $consumer)) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token is not associated with the specified consumer'
            );
        }
        if (Token::TYPE_ACCESS != $token->getType()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token is not an access token'
            );
        }
        if ($token->getRevoked()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Access token has been revoked'
            );
        }

        return $token->getSecret();
    }

    /**
     * {@inheritdoc}
     */
    public function validateAccessToken($accessToken)
    {
        $token = $this->_getToken($accessToken);
        // Make sure a consumer is associated with the token.
        $this->_getConsumer($token->getConsumerId());

        if (Token::TYPE_ACCESS != $token->getType()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token is not an access token'
            );
        }

        if ($token->getRevoked()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Access token has been revoked'
            );
        }

        return $token->getConsumerId();
    }

    /**
     * {@inheritdoc}
     */
    public function validateOauthToken($oauthToken)
    {
        return strlen($oauthToken) == \Magento\Framework\Oauth\Helper\Oauth::LENGTH_TOKEN;
    }

    /**
     * {@inheritdoc}
     */
    public function getConsumerByKey($consumerKey)
    {
        if (strlen($consumerKey) != \Magento\Framework\Oauth\Helper\Oauth::LENGTH_CONSUMER_KEY) {
            throw new \Magento\Framework\Oauth\Exception(
                'Consumer key is not the correct length'
            );
        }

        $consumer = $this->_consumerFactory->create()->loadByKey($consumerKey);

        if (!$consumer->getId()) {
            throw new \Magento\Framework\Oauth\Exception(
                'A consumer having the specified key does not exist'
            );
        }

        return $consumer;
    }

    /**
     * Validate 'oauth_verifier' parameter.
     *
     * @param string $oauthVerifier
     * @param string $tokenVerifier
     * @return void
     * @throws \Magento\Framework\Oauth\Exception
     */
    protected function _validateVerifierParam($oauthVerifier, $tokenVerifier)
    {
        if (!is_string($oauthVerifier)) {
            throw new \Magento\Framework\Oauth\Exception(
                'Verifier is invalid'
            );
        }
        if (!$this->validateOauthToken($oauthVerifier)) {
            throw new \Magento\Framework\Oauth\Exception(
                'Verifier is not the correct length'
            );
        }
        if ($tokenVerifier != $oauthVerifier) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token verifier and verifier token do not match'
            );
        }
    }

    /**
     * Get consumer by consumer_id for a given token.
     *
     * @param int $consumerId
     * @return \Magento\Framework\Oauth\ConsumerInterface
     * @throws \Magento\Framework\Oauth\Exception
     */
    protected function _getConsumer($consumerId)
    {
        $consumer = $this->_consumerFactory->create()->load($consumerId);

        if (!$consumer->getId()) {
            throw new \Magento\Framework\Oauth\Exception(
                'A consumer with the ID %1 does not exist',
                [$consumerId]
            );
        }

        return $consumer;
    }

    /**
     * Load token object and validate it.
     *
     * @param string $token
     * @return Token
     * @throws \Magento\Framework\Oauth\Exception
     */
    protected function _getToken($token)
    {
        if (!$this->validateOauthToken($token)) {
            throw new \Magento\Framework\Oauth\Exception(
                'Token is not the correct length'
            );
        }

        $tokenObj = $this->_tokenFactory->create()->load($token, 'token');

        if (!$tokenObj->getId()) {
            throw new \Magento\Framework\Oauth\Exception(
                'Specified token does not exist'
            );
        }

        return $tokenObj;
    }

    /**
     * Load token object given a consumer Id.
     *
     * @param int $consumerId - The Id of the consumer.
     * @return Token
     * @throws \Magento\Framework\Oauth\Exception
     */
    public function getIntegrationTokenByConsumerId($consumerId)
    {
        $token = $this->token->loadByConsumerIdAndUserType($consumerId, UserContextInterface::USER_TYPE_INTEGRATION);

        if (!$token->getId()) {
            throw new \Magento\Framework\Oauth\Exception(
                'A token with consumer ID %1 does not exist',
                [$consumerId]
            );
        }

        return $token;
    }

    /**
     * Check if token belongs to the same consumer.
     *
     * @param Token $token
     * @param \Magento\Framework\Oauth\ConsumerInterface $consumer
     * @return bool
     */
    protected function _isTokenAssociatedToConsumer($token, $consumer)
    {
        return $token->getConsumerId() == $consumer->getId();
    }
}
