<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Distance;
use DTApi\Models\Job;
use DTApi\Repository\BookingRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected BookingRepository $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

     /**
     * Get the list of jobs based on the request.
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $response = [];

        try {
            $user_id = $request->get('user_id');

            if ($user_id) {
                // Get jobs for a specific user
                $response = $this->repository->getUsersJobs($user_id);
            } elseif ($this->isAdmin($request)) {
                // Get all jobs for admin users
                $response = $this->repository->getAll($request);
            }
        } catch (\Exception $e) {
            // Handle exceptions
            return response(['error' => $e->getMessage()], 500);
        }

        return response($response);
    }

    /**
     * Get a specific job by ID.
     *
     * @param int $id
     * @return Response
     */
    public function show(int $id): Response
    {
        try {
            $job = $this->repository->with('translatorJobRel.user')->find($id);
            return response($job);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a new job.
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        try {
            $data = $request->all();
            $response = $this->repository->store($request->__authenticatedUser, $data);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a job by ID.
     *
     * @param int $id
     * @param Request $request
     * @return Response
     */
    public function update(int $id, Request $request): Response
    {
        try {
            $data = $request->all();
            $cuser = $request->__authenticatedUser;
            $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a job via email.
     *
     * @param Request $request
     * @return Response
     */
    public function immediateJobEmail(Request $request): Response
    {
        try {
            $adminSenderEmail = config('app.adminemail');
            $data = $request->all();
            $response = $this->repository->storeJobEmail($data);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if the authenticated user is an admin.
     *
     * @param Request $request
     * @return bool
     */
    protected function isAdmin(Request $request): bool
    {
        $userType = $request->__authenticatedUser->user_type;
        return $userType == env('ADMIN_ROLE_ID') || $userType == env('SUPERADMIN_ROLE_ID');
    }

    /**
     * Get the job history for a user.
     *
     * @param Request $request
     * @return Response|null
     */
    public function getHistory(Request $request): Response
    {
        try {
            if ($user_id = $request->get('user_id')) {
                $response = $this->repository->getUsersJobsHistory($user_id, $request);
                return response($response);
            }

            return null;
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Accept a job.
     *
     * @param Request $request
     * @return Response
     */
    public function acceptJob(Request $request): Response
    {
        try {
            $data = $request->all();
            $user = $request->__authenticatedUser;

            $response = $this->repository->acceptJob($data, $user);

            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Accept a job with a specific ID.
     *
     * @param Request $request
     * @return Response
     */
    public function acceptJobWithId(Request $request): Response
    {
        try {
            $data = $request->get('job_id');
            $user = $request->__authenticatedUser;

            $response = $this->repository->acceptJobWithId($data, $user);

            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a job.
     *
     * @param Request $request
     * @return Response
     */
    public function cancelJob(Request $request): Response
    {
        try {
            $data = $request->all();
            $user = $request->__authenticatedUser;

            $response = $this->repository->cancelJobAjax($data, $user);

            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return Response
     */
    public function endJob(Request $request): Response
    {
        try {
            $data = $request->all();
            $response = $this->repository->endJob($data);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle the case when the customer does not call.
     *
     * @param Request $request
     * @return Response
     */
    public function customerNotCall(Request $request): Response
    {
        try {
            $data = $request->all();
            $response = $this->repository->customerNotCall($data);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get potential jobs for a user.
     *
     * @param Request $request
     * @return Response
     */
    public function getPotentialJobs(Request $request): Response
    {
        try {
            $user = $request->__authenticatedUser;
            $response = $this->repository->getPotentialJobs($user);
            return response($response);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update distance and job details.
     *
     * @param Request $request
     * @return Response
     */
    public function distanceFeed(Request $request): Response
    {
        try {
            $data = $request->all();

            $distance = $data['distance'] ?? '';
            $time = $data['time'] ?? '';
            $jobid = $data['jobid'] ?? '';
            $session = $data['session_time'] ?? '';
            $flagged = $data['flagged'] === 'true' ? 'yes' : 'no';
            $manually_handled = $data['manually_handled'] === 'true' ? 'yes' : 'no';
            $by_admin = $data['by_admin'] === 'true' ? 'yes' : 'no';
            $admincomment = $data['admincomment'] ?? '';

            if (empty($jobid)) {
                throw ValidationException::withMessages(['jobid' => 'The jobid field is required.']);
            }

            if (!empty($time) || !empty($distance)) {
                Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
            }

            if (!empty($admincomment) || !empty($session) || $flagged === 'yes' || $manually_handled === 'yes' || $by_admin === 'yes') {
                Job::where('id', '=', $jobid)->update([
                    'admin_comments' => $admincomment,
                    'flagged' => $flagged,
                    'session_time' => $session,
                    'manually_handled' => $manually_handled,
                    'by_admin' => $by_admin
                ]);
            }

            return response('Record updated!');
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reopen a job.
     *
     * @param Request $request
     * @return Response
     */
    public function reopen(Request $request): Response
    {
        try {
            $data = $request->all();
            $response = $this->repository->reopen($data);
            return response($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred during job reopening.'], 500);
        }
    }

    /**
     * Resend notifications for a job.
     *
     * @param Request $request
     * @return Response
     */
    public function resendNotifications(Request $request): Response
    {
        try {
            $data = $request->all();
            $job = $this->repository->find($data['jobid']);
            $job_data = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $job_data, '*');

            return response(['success' => 'Push sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while resending notifications.'], 500);
        }
    }

    /**
     * Resend SMS notifications to the translator.
     *
     * @param Request $request
     * @return Response
     */
    public function resendSMSNotifications(Request $request): Response
    {
        try {
            $data = $request->all();
            $job = $this->repository->find($data['jobid']);
            $job_data = $this->repository->jobToData($job);

            $this->repository->sendSMSNotificationToTranslator($job);

            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }
}
