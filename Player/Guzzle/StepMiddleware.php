<?php

/*
 * This file is part of the Blackfire Player package.
 *
 * (c) Fabien Potencier <fabien@blackfire.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Blackfire\Player\Guzzle;

use Blackfire\Player\Step;
use Blackfire\Player\ValueBag;
use Blackfire\Player\Exception\InvalidArgumentException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError as ExpressionSyntaxError;
use Psr\Log\LoggerInterface;

/**
 * @author Fabien Potencier <fabien@blackfire.io>
 */
class StepMiddleware
{
    private $handler;
    private $requestFactory;
    private $extensions;
    private $values;
    private $logger;
    private $previousResponse;
    private $previousCrawler;
    private $language;

    public function __construct(callable $handler, RequestFactory $requestFactory, ExpressionLanguage $language, array $extensions = [], LoggerInterface $logger = null)
    {
        $this->handler = $handler;
        $this->requestFactory = $requestFactory;
        $this->language = $language;
        $this->extensions = $extensions;
        $this->logger = $logger;
    }

    public static function create(RequestFactory $requestFactory, ExpressionLanguage $language, array $extensions = [], LoggerInterface $logger = null)
    {
        return function (callable $handler) use ($requestFactory, $language, $extensions, $logger) {
            return new self($handler, $requestFactory, $language, $extensions, $logger);
        };
    }

    /**
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->handler;

        if (!isset($options['step'])) {
            return $fn($request, $options);
        }

        if (!$options['step'] instanceof Step) {
            throw new InvalidArgumentException('The "step" option must be an instance of Blackfire\Player\Step.');
        }

        $step = $options['step'];

        $values = null;
        if (isset($options['values'])) {
            if (!$options['values'] instanceof ValueBag) {
                throw new InvalidArgumentException('The "values" option must be an instance of Blackfire\Player\ValueBag.');
            }

            $values = $options['values'];
        } else {
            $values = new ValueBag();
        }

        $options = $this->prepareRequest($step, $values, $request, $options);

        $msg = sprintf('Step %d: %s %s %s%s', $step->getIndex(), $step->getName(), $request->getMethod(), $request->getUri(), $step->getSamples() > 1 ? sprintf(' (%d samples)', $step->getSamples()) : '');
        $this->logger and $this->logger->info($msg, ['request' => $request->getHeaderLine('X-Request-Id')]);

        return $fn($request, $options)
            ->then(function (ResponseInterface $response) use ($request, $options, $step, $values) {
                return $this->processResponse($request, $options, $response, $step, $values);
            });
    }

    /**
     * @param RequestInterface                   $request
     * @param array                              $options
     * @param ResponseInterface|PromiseInterface $response
     *
     * @return ResponseInterface|PromiseInterface
     */
    public function processResponse(RequestInterface $request, array $options, ResponseInterface $response, Step $step, ValueBag $values)
    {
        $crawler = $this->createCrawler($request->getUri(), $response);

        foreach ($this->extensions as $extension) {
            $extension->processResponse($request, $response, $step, $values, $crawler);
        }

        if (!$step = $step->getNext()) {
            return $response;
        }

        $options['step'] = $step;
        $nextRequest = $this->requestFactory->create($step, $values, $request, $response, $crawler);

        return $this($nextRequest, $options);
    }

    private function createCrawler($uri, ResponseInterface $response)
    {
        $crawler = null;
        if ($response->hasHeader('Content-Type') && (false !== strpos($response->getHeaderLine('Content-Type'), 'html') || false !== strpos($response->getHeaderLine('Content-Type'), 'xml'))) {
            $crawler = new Crawler(null, $uri);
            $crawler->addContent((string) $response->getBody(), $response->getHeaderLine('Content-Type'));
        }

        return $crawler;
    }

    private function prepareRequest(Step $step, ValueBag $values, RequestInterface $request, $options)
    {
        $options['allow_redirects'] = false;

        if (!$step->getDelay()) {
            $options['delay'] = 0;
        } else {
            try {
                $options['delay'] = $this->language->evaluate($step->getDelay(), $values->all(true));
            } catch (ExpressionSyntaxError $e) {
                $msg = sprintf('Delay syntax error in "%s": %s', $step->getDelay(), $e->getMessage());

                $this->logger and $this->logger->critical($msg, ['request' => $request->getHeaderLine('X-Request-Id')]);

                throw new InvalidArgumentException($msg);
            }
        }

        unset($options['expectations']);
        if ($step->getExpectations()) {
            $options['expectations'] = $step->getExpectations();
        }

        unset($options['extractions']);
        if ($step->getExtractions()) {
            $options['extractions'] = $step->getExtractions();
        }

        foreach ($this->extensions as $extension) {
            $options = $extension->prepareRequest($step, $values, $request, $options);
        }

        return $options;
    }
}
