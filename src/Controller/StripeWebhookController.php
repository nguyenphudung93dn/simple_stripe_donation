<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_stripe_donation\Service\WebhookServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles POST /stripe-donation/webhook.
 *
 * Publicly accessible (see routing.yml: _access: 'TRUE'), but every request
 * must carry a valid Stripe-Signature header or WebhookService rejects it
 * with a 400 before any business logic runs. This is the module's only
 * unauthenticated route by design — Stripe cannot send session cookies or
 * CSRF tokens, so signature verification is the access control here.
 */
class StripeWebhookController extends ControllerBase {

  protected WebhookServiceInterface $webhookService;

  public function __construct(WebhookServiceInterface $webhookService) {
    $this->webhookService = $webhookService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_stripe_donation.webhook_service')
    );
  }

  /**
   * Handles the incoming webhook request.
   */
  public function handle(Request $request): JsonResponse {
    $payload = $request->getContent();
    $sigHeader = (string) $request->headers->get('Stripe-Signature', '');

    $result = $this->webhookService->handleWebhook($payload, $sigHeader);

    return new JsonResponse(
      ['received' => (bool) $result['success'], 'message' => $result['message']],
      $result['status_code']
    );
  }

}
