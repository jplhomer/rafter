<?php

namespace App\Http\Controllers;

use App\Exceptions\GitHubAutoMergedException;
use App\Exceptions\GitHubDeploymentConflictException;
use App\Http\Requests\GitHubHookDeploymentRequest;
use App\Http\Requests\GitHubHookPushRequest;
use App\Http\Requests\GitHubHookStatusRequest;
use App\PendingSourceProviderDeployment;
use App\Services\GitHub;
use Illuminate\Http\Request;

class GitHubHookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!GitHub::verifyWebhookPayload($request)) {
            return response('', 403);
        }

        $event = $request->header('X-GitHub-Event');
        $methodName = 'handle' . ucfirst($event);

        if (method_exists($this, $methodName)) {
            return $this->{$methodName}();
        }

        return response('', 200);
    }

    public function handlePush()
    {
        $request = app(GitHubHookPushRequest::class);

        foreach ($request->environments() as $environment) {
            $pendingDeployment = PendingSourceProviderDeployment::make()
                ->forEnvironment($environment)
                ->forHash($request->hash())
                ->byUserId($environment->getInitiator($request->senderEmail())->id);

            try {
                $environment->sourceProvider()->client()->createDeployment($pendingDeployment);
            } catch (GitHubAutoMergedException $e) {
                logger("Canceled deployment for {$request->getRepository()}#{$request->hash()} because it auto-merged an upstream branch.");

                continue;
            } catch (GitHubDeploymentConflictException $e) {
                logger("Canceled deployment for {$request->getRepository()}#{$request->hash()}: {$e->getMessage()}");

                continue;
            }
        }

        return response('', 200);
    }

    public function handleStatus()
    {
        $request = app(GitHubHookStatusRequest::class);

        foreach ($request->environments() as $environment) {
            if (
                !$environment->getOption('wait_for_checks') ||
                !$environment->sourceProvider()->client()->commitChecksSuccessful($request->repository(), $request->hash())
            ) {
                continue;
            }

            $latestHashOnBranch = $environment->sourceProvider()->client()->latestHashFor(
                $request->repository(),
                $environment->branch
            );

            if ($latestHashOnBranch != $request->hash()) {
                continue;
            }

            $pendingDeployment = PendingSourceProviderDeployment::make()
                ->forEnvironment($environment)
                ->forHash($request->hash())
                ->byUserId($environment->getInitiator($request->senderEmail())->id);

            try {
                $environment->sourceProvider()->client()->createDeployment($pendingDeployment);
            } catch (GitHubAutoMergedException $e) {
                logger("Canceled deployment for {$request->repository()}#{$request->hash()} because it auto-merged an upstream branch.");

                continue;
            } catch (GitHubDeploymentConflictException $e) {
                logger("Canceled deployment for {$request->repository()}#{$request->hash()}: {$e->getMessage()}");

                continue;
            }
        }
    }

    public function handleDeployment()
    {
        $request = app(GitHubHookDeploymentRequest::class);

        $environment = $request->getEnvironment();

        if (
            !$environment
            || $environment->sourceProvider()->installation_id != $request->installationId()
            || $request->manual()
        ) {
            return response('');
        }

        $deployment = $environment->deployHash($request->hash(), $request->initiatorId());
        $deployment->meta = [
            'github_deployment_id' => $request->id(),
        ];
        $deployment->save();

        return response('');
    }
}
