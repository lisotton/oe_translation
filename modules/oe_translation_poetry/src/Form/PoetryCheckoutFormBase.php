<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_poetry\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\oe_translation_poetry\Poetry;
use Drupal\oe_translation_poetry\PoetryJobQueueFactory;
use Drupal\oe_translation_poetry\PoetryTranslatorUI;
use Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface;
use Drupal\tmgmt\JobInterface;
use EC\Poetry\Messages\Responses\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles the checkout form for Poetry requests.
 */
abstract class PoetryCheckoutFormBase extends FormBase {

  /**
   * The type of request (the product). Usually a translation request.
   *
   * @var string
   */
  protected $requestType = 'TRA';

  /**
   * The job queue factory.
   *
   * @var \Drupal\oe_translation_poetry\PoetryJobQueueFactory
   */
  protected $queueFactory;

  /**
   * The Poetry client.
   *
   * @var \Drupal\oe_translation_poetry\Poetry
   */
  protected $poetry;

  /**
   * The content formatter.
   *
   * @var \Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface
   */
  protected $contentFormatter;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * PoetryCheckoutForm constructor.
   *
   * @param \Drupal\oe_translation_poetry\PoetryJobQueueFactory $queueFactory
   *   The job queue factory.
   * @param \Drupal\oe_translation_poetry\Poetry $poetry
   *   The Poetry client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface $contentFormatter
   *   The content formatter.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(PoetryJobQueueFactory $queueFactory, Poetry $poetry, MessengerInterface $messenger, PoetryContentFormatterInterface $contentFormatter, LoggerChannelFactoryInterface $loggerChannelFactory) {
    $this->queueFactory = $queueFactory;
    $this->poetry = $poetry;
    $this->messenger = $messenger;
    $this->contentFormatter = $contentFormatter;
    $this->logger = $loggerChannelFactory->get('oe_translation_poetry');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('oe_translation_poetry.job_queue_factory'),
      $container->get('oe_translation_poetry.client.default'),
      $container->get('messenger'),
      $container->get('oe_translation_poetry.html_formatter'),
      $container->get('logger.factory')
    );
  }

  /**
   * The operation of the request: CREATE, UPDATE, DELETE.
   *
   * @return string
   *   The operation.
   */
  abstract protected function getRequestOperation(): string;

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node entity for which the translations are going to be requested.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ContentEntityInterface $node = NULL) {
    $translator_settings = $this->poetry->getTranslatorSettings();

    $form_state->set('entity', $node);

    $form['#tree'] = TRUE;

    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Request details'),
      '#open' => TRUE,
    ];

    $form['details']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Requested delivery date'),
      '#required' => TRUE,
    ];

    $default_contact = $translator_settings['contact'] ?? [];
    $form['details']['contact'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contact information'),
    ];

    foreach (PoetryTranslatorUI::getContactFieldNames('contact') as $name => $label) {
      $form['details']['contact'][$name] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $default_contact[$name] ?? '',
        '#required' => TRUE,
      ];
    }

    $default_organisation = $translator_settings['organisation'] ?? [];
    $form['details']['organisation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Organisation information'),
    ];

    foreach (PoetryTranslatorUI::getContactFieldNames('organisation') as $name => $label) {
      $form['details']['organisation'][$name] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $default_organisation[$name] ?? '',
        '#required' => TRUE,
      ];
    }

    $form['details']['comment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Comment'),
      '#description' => $this->t('Optional remark about the translation request.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send request'),
      '#button_type' => 'primary',
      '#submit' => ['::submitRequest'],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel and delete job'),
      '#button_type' => 'secondary',
      '#submit' => ['::cancelRequest'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Check delivery date is not in the past.
    $delivery_date = new \DateTime($form_state->getValue('details')['date']);
    $today = new \DateTime();
    if ($delivery_date->format('Y-m-d') < $today->format('Y-m-d')) {
      $form_state->setErrorByName('details][date', t('@delivery_date cannot be in the past.', ['@delivery_date' => $this->t('Requested delivery date')]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // The submit handler is submitRequest().
  }

  /**
   * Returns the title of the form page.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node entity to use when generating the title.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title for the form page.
   */
  public function getPageTitle(ContentEntityInterface $node = NULL): TranslatableMarkup {
    $queue = $this->queueFactory->get($node);
    $entity = $queue->getEntity();
    $target_languages = $queue->getTargetLanguages();
    $target_languages = count($target_languages) > 1 ? implode(', ', $target_languages) : array_shift($target_languages);
    return $this->t('Send request to DG Translation for <em>@entity</em> in <em>@target_languages</em>', ['@entity' => $entity->label(), '@target_languages' => $target_languages]);
  }

  /**
   * Submits the request to Poetry.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitRequest(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->get('entity');
    $queue = $this->queueFactory->get($entity);
    $translator_settings = $this->poetry->getTranslatorSettings();
    $jobs = $queue->getAllJobs();
    $identifier = $this->poetry->getIdentifierForContent($entity);
    $identifier->setProduct($this->requestType);

    $date = new \DateTime($form_state->getValue('details')['date']);
    $formatted_date = $date->format('d/m/Y');

    /** @var \EC\Poetry\Messages\Requests\CreateTranslationRequest $message */
    $message = $this->poetry->get('request.create_translation_request');
    $message->setIdentifier($identifier);

    // Build the details.
    $details = $message->withDetails();
    $details->setDelay($formatted_date);

    if ($form_state->getValue('details')['comment']) {
      $details->setRemark($form_state->getValue('details')['comment']);
    }

    // We use the formatted identifier as the user reference.
    $details->setClientId($identifier->getFormattedIdentifier());
    $title = $this->createRequestTitle(reset($jobs));
    $details->setTitle($title);
    $details->setApplicationId($translator_settings['application_reference']);
    $details->setReferenceFilesRemark($entity->toUrl()->setAbsolute()->toString());
    $details
      ->setProcedure('NEANT')
      ->setDestination('PUBLIC')
      ->setType('INTER');

    // Add the organisation information.
    $organisation_information = [
      'setResponsible' => 'responsible',
      'setAuthor' => 'author',
      'setRequester' => 'requester',
    ];
    foreach ($organisation_information as $method => $name) {
      $details->$method($form_state->getValue('details')['organisation'][$name]);
    }

    $message->setDetails($details);

    // Build the contact information.
    foreach (PoetryTranslatorUI::getContactFieldNames('contact') as $name => $label) {
      $message->withContact()
        ->setType($name)
        ->setNickname($form_state->getValue('details')['contact'][$name]);
    }

    // Build the return endpoint information.
    $settings = $this->poetry->getSettings();
    $username = $settings['notification.username'] ?? NULL;
    $password = $settings['notification.password'] ?? NULL;
    $return = $message->withReturnAddress();
    $return->setUser($username);
    $return->setPassword($password);
    // The notification endpoint WSDL.
    $return->setAddress(Url::fromRoute('oe_translation_poetry.notifications')->setAbsolute()->toString() . '?wsdl');
    // The notification endpoint WSDL action method.
    $return->setPath('handle');
    // The return is a webservice and not an email.
    $return->setType('webService');
    $return->setAction($this->getRequestOperation());
    $message->setReturnAddress($return);

    $source = $message->withSource();
    $source->setFormat('HTML');
    $source->setName('content.html');
    $formatted_content = $this->contentFormatter->export(reset($jobs));
    $source->setFile(base64_encode($formatted_content->__toString()));
    $source->setLegiswriteFormat('No');
    $source->withSourceLanguage()
      ->setCode(strtoupper($entity->language()->getId()))
      ->setPages(1);
    $message->setSource($source);

    foreach ($jobs as $job) {
      $message->withTarget()
        ->setLanguage(strtoupper($job->getRemoteTargetLanguage()))
        ->setFormat('HTML')
        ->setAction($this->getRequestOperation())
        ->setDelay($formatted_date);
    }

    try {
      $client = $this->poetry->getClient();
      /** @var \EC\Poetry\Messages\Responses\ResponseInterface $response */
      $response = $client->send($message);
      $this->handlePoetryResponse($response, $form_state);

      // If we request a new number by setting a sequence, update the global
      // identifier number with the new number that came for future requests.
      if ($identifier->getSequence()) {
        $this->poetry->setGlobalIdentifierNumber($response->getIdentifier()->getNumber());
      }

      $this->redirectBack($form_state);
      $queue->reset();
      $this->messenger->addStatus($this->t('The request has been sent to DGT.'));
    }
    catch (\Exception $exception) {
      $this->logger->error($exception->getMessage());
      $this->messenger->addError($this->t('There was an error making the request to DGT.'));
      $this->redirectBack($form_state);
      $queue->reset();
    }
  }

  /**
   * Cancels the request and deletes the jobs that had been created.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelRequest(array &$form, FormStateInterface $form_state): void {
    $this->cancelAndRedirect($form_state);
    $this->messenger->addStatus($this->t('The translation request has been cancelled and the corresponding jobs deleted.'));
  }

  /**
   * Deletes the jobs and redirects the user back.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function cancelAndRedirect(FormStateInterface $form_state): void {
    $this->redirectBack($form_state);
    $queue = $this->queueFactory->get($form_state->get('entity'));
    $jobs = $queue->getAllJobs();
    foreach ($jobs as $job) {
      $job->delete();
    }
    $queue->reset();
  }

  /**
   * Sets the redirect back to the content onto the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function redirectBack(FormStateInterface $form_state): void {
    $queue = $this->queueFactory->get($form_state->get('entity'));
    $destination = $queue->getDestination();
    if ($destination) {
      $form_state->setRedirectUrl($destination);
    }
  }

  /**
   * Creates the title of the request.
   *
   * It uses the configured prefix, site ID and the title of the Job (one of the
   * jobs as they are identical).
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job to get the label from.
   *
   * @return string
   *   The title.
   */
  protected function createRequestTitle(JobInterface $job): string {
    $settings = $this->poetry->getTranslatorSettings();
    return (string) new FormattableMarkup('@prefix: @site_id - @title', [
      '@prefix' => $settings['title_prefix'],
      '@site_id' => $settings['site_id'],
      '@title' => $job->label(),
    ]);
  }

  /**
   * Handles a response that comes from Poetry.
   *
   * @param \EC\Poetry\Messages\Responses\ResponseInterface $response
   *   The response.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function handlePoetryResponse(ResponseInterface $response, FormStateInterface $form_state): void {
    $queue = $this->queueFactory->get($form_state->get('entity'));
    $jobs = $queue->getAllJobs();
    if (!$response->isSuccessful()) {
      $this->rejectJobs($response, $jobs);
    }

    /** @var \EC\Poetry\Messages\Components\Identifier $identifier */
    $identifier = $response->getIdentifier();
    $identifier_values = [
      'code' => $identifier->getCode(),
      'year' => $identifier->getYear(),
      'number' => $identifier->getNumber(),
      'version' => $identifier->getVersion(),
      'part' => $identifier->getPart(),
      'product' => $identifier->getProduct(),
    ];

    $date = new \DateTime($form_state->getValue('details')['date']);

    foreach ($jobs as $job) {
      $job->set('poetry_request_id', $identifier_values);
      $job->set('poetry_request_date', $date->format('Y-m-d\TH:i:s'));
      // Submit the job. This will also save it.
      $job->submitted();
    }
  }

  /**
   * Rejects the jobs after a request failure.
   *
   * Sets the response warnings and error messages onto the jobs.
   *
   * @param \EC\Poetry\Messages\Responses\ResponseInterface $response
   *   The response.
   * @param \Drupal\tmgmt\JobInterface[] $jobs
   *   The jobs to reject.
   *
   * @throws \Exception
   */
  protected function rejectJobs(ResponseInterface $response, array $jobs): void {
    $warnings = $response->getWarnings() ? implode('. ', $response->getWarnings()) : NULL;
    $errors = $response->getErrors() ? implode('. ', $response->getErrors()) : NULL;
    $job_ids = [];

    foreach ($jobs as $job) {
      if ($warnings) {
        $job->addMessage(new FormattableMarkup('There were warnings with this request: @warnings', ['@warnings' => $warnings]));
      }
      if ($errors) {
        $job->addMessage(new FormattableMarkup('There were errors with this request: @errors', ['@errors' => $errors]));
      }

      $job->rejected();
      $job_ids[] = $job->id();
    }

    $message = new FormattableMarkup('The DGT request with the following jobs has been rejected upon submission: @jobs The messages have been saved in the jobs.', ['@jobs' => implode(', ', $job_ids)]);
    throw new \Exception($message->__toString());
  }

}
