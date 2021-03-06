<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

initMainRoutes($app);
function initMainRoutes($app) {
  $routes = $app['controllers_factory'];

  $routes->match('/', function(Request $request) use($app) {
    if ($app['user'])
    {
      return $app->redirect($app->path('app'));
    }
    return $app['twig']->render('home.twig');
  })
  ->method('GET|POST|HEAD')
  ->bind('home');



  $routes->get('/logout', function() use($app) {
    $app['session']->set('user_id', null);
    $app['session']->save();
    return $app->redirect($app->path('home'));
  })
  ->bind('logout');



  $routes->get('/app', function() use($app) {
    if (!$app['user']) // if logged in, go to app
    {
      return $app->redirect($app->path('home'));
    }

    if (!$app['user']['github_token'])
    {
      return $app->redirect($app->path('github_connect'));
    }

    if (!$app['user']['github_repo'])
    {
      return $app->forward($app->path('github_select_repo'));
    }

    if (!$app['user']['github_branch'])
    {
      return $app->forward($app->path('github_select_branch'));
    }

    if (!$app['user']['posts_path'])
    {
      return $app->forward($app->path('github_select_path'));
    }

    if ($app['session']->get('just_created_new_account'))
    {
      $app['session']->remove('just_created_new_account');
      $app['session']->save();
      return $app->redirect($app->path('about').'?new_account=1');
    }

    $lastPostPublishEvent = $app['pdo']->fetchOne('SELECT * FROM event WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1', [$app['user']['id'], 'post.publish']);
    $email = $app['pdo']->fetchOne('SELECT * FROM email WHERE user_id = ? AND is_primary = 1 LIMIT 1', $app['user']['id']);

    return $app['twig']->render('app.twig', [
      'user' => $app['user'],
      'lastPostPublishEvent' => $lastPostPublishEvent,
      'primaryEmail' => $email ? $email['email'] : null
    ]);
  })->bind('app');



  $routes->get('/events', function() use($app) {
    if (!$app['user']) // if logged in, go to app
    {
      return $app->redirect($app->path('home'));
    }

    $events = $app['pdo']->fetchAssoc('SELECT * FROM event WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', $app['user']['id']);

    return $app['twig']->render('events.twig', [
      'user' => $app['user'],
      'events' => $events,
    ]);
  })->bind('events');



  $routes->get('/about', function() use($app) {
    return $app['twig']->render('about.twig', [
      'user' => $app['user'],
      'newAccount' => $app['request']->query->get('new_account')
    ]);
  })->bind('about');


  $routes->match('/mailgun_webhook', function(Request $request) use($app) {
    if ($request->getMethod() == 'HEAD')
    {
      return new Response('ok');
    }

    $validRequest = hash_hmac('sha256', $request->get('timestamp') . $request->get('token'), MAILGUN_API_KEY) === $request->get('signature');
    if (!$validRequest)
    {
      return $app->abort(403, "Invalid signature");
    }


    $senderEmail = $request->get('sender');

    $app->log('got email from ' . $senderEmail);

    $user = $app['pdo']->fetchOne('SELECT u.* FROM user u INNER JOIN email e ON u.id = e.user_id AND e.email = ?', $senderEmail);

    if (!$user)
    {
      $app->log('user not found');
      return new Response('ok'); // user not found. we could notify them, but dont wanna deal with spam
    }

    $postText = trim($request->get('body-plain'))."\n";

    $subject = trim($request->get('subject'));
    $tagRegex = '/#[^ ]+$/';
    preg_match($tagRegex, $subject, $matches);
    $tags = $matches ? explode(',', ltrim($matches[0],'#')) : [];
    $postTitle = trim(preg_replace($tagRegex, '', $subject));

    $frontMatterData = [];
    $body = $postText;

    if (preg_match('/^(\r\n|\r|\n)*[\t\f\v ]*---/', $postText))
    {
      $parts = preg_split("/(\r\n|\r|\n)[\t\f\v ]*---[\t\f\v ]*(\r\n|\r|\n)/", "\n".str_replace("---".hex2bin('e2808b')."\n","---\n",$postText), 3); //e2808b = zero-width space

      if (count($parts) == 3)
      {
        try
        {
          $frontMatterData = Symfony\Component\Yaml\Yaml::parse($parts[1]);
        }
        catch (Symfony\Component\Yaml\Exception\ParseException $pe)
        {
          $app->log('Parse exception: ' . $pe->__toString());
          $app['log_event']('post.error', $postTitle, $user['id']);
          $app['mailer']->sendPublishErrorEmail($senderEmail, $postTitle, 'Error parsing YAML frontmatter. ' . $pe->getMessage());
          $app->log('Sent yaml parse error email');
          return new Response('ok');
        }
        $body = trim($parts[2]);
      }
    }

    $frontMatterData['title'] = $postTitle;
    $frontMatterData['date'] = date('Y-m-d H:i:s') . ' UTC';
    if ($tags)
    {
      $frontMatterData['tags'] = $tags;
    }

    if (stripos($user['github_repo'], 'lyoshenka') === 0)
    {
      $frontMatterData['_id_'] = '';
      for ($i = 0; $i < 16; $i++)
      {
        $frontMatterData['_id_'] .= rand(0, 9);
      }
    }

      $frontMatter = Symfony\Component\Yaml\Yaml::dump($frontMatterData);

      $postText = "---\n" . $frontMatter . "---\n\n" . $body;

      $filename = date('Y-m-d') . '-' . rtrim(preg_replace('/[^\p{L}\p{N}]+/', '-', strtolower($postTitle)), '-') . '.md';

      list($githubUsername,$repo) = explode('/', $user['github_repo']);
      $committer = ['name' => 'Begemot', 'email' => $senderEmail];

      try
      {
        $app['github']->authenticate($user['github_token'], null, Github\Client::AUTH_HTTP_TOKEN);
        $fileInfo = $app['github']->api('repo')->contents()->create(
          $githubUsername, $repo, $user['posts_path'].'/'.$filename, $postText, 'post via begemot: '.$postTitle, $user['github_branch'], $committer
        );
        $app->log('Successfully created post');
        $app['log_event']('post.publish', $postTitle, $user['id']);
        $app['mailer']->sendPublishSuccessEmail($senderEmail, $postTitle);
      }
      catch (Github\Exception\RuntimeException $e)
      {
        if (stripos($e->getMessage(), 'Missing required keys "sha" in object') !== false ||
            stripos($e->getMessage(), 'Invalid request. "sha" wasn\'t supplied') !== false)
        {
          $app->log('Post exists for filename "' . $filename . '"');
          $app['log_event']('post.error', $postTitle, $user['id']);
          $app['mailer']->sendPublishErrorEmail($senderEmail, $postTitle, 'A post with the same title already exists for today');
        }
        else
        {
          $app->log('Error creating file on github: ' . $e);
          $app['log_event']('post.error', $postTitle, $user['id']);
          $app['mailer']->sendPublishErrorEmail($senderEmail, $postTitle,
            'Got an error from GitHub: "' . $e->getMessage() . '". If this doesn\'t help clear things up, please forward this email to ' . $app['config.support_email']
          );
        }
      }

    return new Response('ok');
  })
  ->method('POST|HEAD');



  $app->mount('/', $routes);
}
