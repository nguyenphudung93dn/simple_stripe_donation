<?php

/**
 * @file
 * Views integration for Simple Stripe Donation.
 *
 * Exposes the {simple_stripe_donation} table as a Views base table so the
 * admin donation report can be a normal, site-builder-editable View (like
 * /admin/content) instead of a hand-rolled controller.
 */

use Drupal\simple_stripe_donation\Service\DonationServiceInterface;

/**
 * Implements hook_views_data().
 */
function simple_stripe_donation_views_data() {
  $data = [];

  $data['simple_stripe_donation']['table']['group'] = t('Simple Stripe Donation');
  $data['simple_stripe_donation']['table']['base'] = [
    'field' => 'id',
    'title' => t('Donations'),
    'help' => t('Donation records made through Stripe Checkout.'),
  ];

  $data['simple_stripe_donation']['id'] = [
    'title' => t('Donation ID'),
    'help' => t('The internal donation ID.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
  ];

  $data['simple_stripe_donation']['uuid'] = [
    'title' => t('UUID'),
    'help' => t('Universally unique identifier, safe to expose to the donor as a reference.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['amount'] = [
    'title' => t('Amount'),
    'help' => t('Donation amount, formatted in major currency units (e.g. 25.50).'),
    'field' => ['id' => 'simple_stripe_donation_amount'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'simple_stripe_donation_amount'],
  ];

  $data['simple_stripe_donation']['currency'] = [
    'title' => t('Currency'),
    'help' => t('ISO 4217 currency code.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['status'] = [
    'title' => t('Status'),
    'help' => t('Donation status: pending, succeeded, failed, expired or refunded.'),
    'field' => ['id' => 'standard'],
    'filter' => [
      'id' => 'in_operator',
      'options callback' => 'simple_stripe_donation_status_options',
    ],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['donor_name'] = [
    'title' => t('Donor name'),
    'help' => t('Donor supplied name, if provided.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['donor_email'] = [
    'title' => t('Donor email'),
    'help' => t('Donor supplied email address.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['anonymous'] = [
    'title' => t('Anonymous'),
    'help' => t('Whether the donor asked to be listed anonymously.'),
    'field' => ['id' => 'boolean'],
    'filter' => ['id' => 'boolean'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['stripe_payment_intent_id'] = [
    'title' => t('Stripe payment intent ID'),
    'help' => t('The Stripe PaymentIntent ID once available.'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'sort' => ['id' => 'standard'],
  ];

  $data['simple_stripe_donation']['created'] = [
    'title' => t('Created'),
    'help' => t('Date the donation record was created (pending).'),
    'field' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
    'argument' => ['id' => 'date'],
  ];

  $data['simple_stripe_donation']['operations'] = [
    'title' => t('Operations'),
    'help' => t('Provides a "Refund" link for eligible, permitted donations.'),
    'field' => ['id' => 'simple_stripe_donation_operations'],
  ];

  $data['simple_stripe_donation']['simple_stripe_donation_summary'] = [
    'title' => t('Donation summary statistics'),
    'help' => t('Total/succeeded counts and total/average amount tiles, computed over the current exposed filters.'),
    'area' => ['id' => 'simple_stripe_donation_summary'],
  ];

  return $data;
}

/**
 * Options callback for the 'status' Views exposed filter.
 */
function simple_stripe_donation_status_options() {
  return [
    DonationServiceInterface::STATUS_PENDING => t('Pending'),
    DonationServiceInterface::STATUS_SUCCEEDED => t('Succeeded'),
    DonationServiceInterface::STATUS_FAILED => t('Failed'),
    DonationServiceInterface::STATUS_EXPIRED => t('Expired'),
    DonationServiceInterface::STATUS_REFUNDED => t('Refunded'),
  ];
}
