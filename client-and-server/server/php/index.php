<?php
use Slim\Http\Request;
use Slim\Http\Response;
use Stripe\Stripe;

require 'vendor/autoload.php';

$ENV_PATH = '../..';
$dotenv = Dotenv\Dotenv::create(realpath($ENV_PATH));
$dotenv->load();

require './config.php';

$app = new \Slim\App;

// Instantiate the logger as a dependency
$container = $app->getContainer();
$container['logger'] = function ($c) {
  $settings = $c->get('settings')['logger'];
  $logger = new Monolog\Logger($settings['name']);
  $logger->pushProcessor(new Monolog\Processor\UidProcessor());
  $logger->pushHandler(new Monolog\Handler\StreamHandler(__DIR__ . '/logs/app.log', \Monolog\Logger::DEBUG));
  return $logger;
};

$app->add(function ($request, $response, $next) {
    Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));
    return $next($request, $response);
});
  

$app->get('/', function (Request $request, Response $response, array $args) {   
    return $response->write(file_get_contents('../../client/index.html'));

});

$app->get('/public-key', function (Request $request, Response $response, array $args) {
  $pub_key = getenv('STRIPE_PUBLIC_KEY');
  return $response->withJson([ 'publicKey' => $pub_key ]);
});

$app->get('/checkout-session', function (Request $request, Response $response, array $args) {
  $id = $request->getQueryParams()['sessionId'];
  $checkout_session = \Stripe\Checkout\Session::retrieve($id);

  return $response->withJson($checkout_session);
});


$app->post('/create-checkout-session', function(Request $request, Response $response, array $args) {
  $domain_url = getenv('DOMAIN');
  $body = json_decode($request->getBody());
  $quantity = $body->quantity;

  $checkout_session = \Stripe\Checkout\Session::create([
    'success_url' => $domain_url . '/success.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $domain_url . '/canceled.html',
    'payment_method_types' => ['card'],
    'line_items' => [[
      'name' => 'Pasha photo',
      'amount' => 500,
      'currency' => 'usd',
      'quantity' => $quantity
    ]]
  ]);

  return $response->withJson(array('sessionId' => $checkout_session['id']));
});

$app->post('/webhook', function(Request $request, Response $response) {
    $logger = $this->get('logger');
    $event = $request->getParsedBody();
    // Parse the message body (and check the signature if possible)
    $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
    if ($webhookSecret) {
      try {
        $event = \Stripe\Webhook::constructEvent(
          $request->getBody(),
          $request->getHeaderLine('stripe-signature'),
          $webhookSecret
        );
      } catch (\Exception $e) {
        return $response->withJson([ 'error' => $e->getMessage() ])->withStatus(403);
      }
    } else {
      $event = $request->getParsedBody();
    }
    $type = $event['type'];
    $object = $event['data']['object'];

    if($type == 'checkout.session.completed') {
      $logger->info('🔔  Payment succeeded! ');
    }

    return $response->withJson([ 'status' => 'success' ])->withStatus(200);
});

$app->run();