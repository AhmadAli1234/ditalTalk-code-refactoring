<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    /**
     * @param Job $model
     * @param MailerInterface $mailer
     * @param LoggerInterface $logger
     */
    function __construct(Job $model, MailerInterface $mailer, LoggerInterface $logger)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    /**
     * Initialize the logger with handlers
     */
    public function initializeLogger(): void
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get jobs for a user based on their user ID.
     *
     * @param int $user_id The ID of the user
     * @return array An array containing emergency jobs, normal jobs, the user object, and the user type
     */
    public function getUsersJobs($user_id): array
    {
        // Find the user by ID
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if (!$cuser) {
            // Return empty results if user not found
            return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => null, 'usertype' => ''];
        }

        if ($cuser->is('customer')) {
            // Retrieve jobs for the customer user
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($cuser->is('translator')) {
            // Retrieve jobs for the translator user
            $jobs = Job::translatorJobs($cuser->id, 'new')
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->get();

            $usertype = 'translator';
        }

        if (!$jobs->isEmpty()) {
            // Separate jobs into emergency and normal jobs
            $jobs->each(function ($jobItem) use (&$emergencyJobs, &$normalJobs, $user_id) {
                if ($jobItem->immediate === 'yes') {
                    $emergencyJobs[] = $jobItem;
                } else {
                    $normalJobs[] = $jobItem;
                }
                // Add 'usercheck' attribute to each job item
                $jobItem['usercheck'] = Job::checkParticularJob($user_id, $jobItem);
            });

            // Sort normal jobs by due date
            $normalJobs = collect($normalJobs)->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
        ];
    }

    /**
     * Get the job history for a user based on their user ID.
     *
     * @param int $user_id The ID of the user
     * @param \Illuminate\Http\Request $request The request object
     * @return array An array containing emergency jobs, normal jobs, jobs, user object, user type, number of pages, and current page number
     */
    public function getUsersJobsHistory($user_id, Request $request): array
    {
        // Get the current page number from the request
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : 1;

        // Find the user by ID
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is('customer')) {
            // Retrieve jobs for the customer user
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $usertype = 'customer';

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0,
            ];
        } elseif ($cuser && $cuser->is('translator')) {
            // Retrieve historic jobs for the translator user
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $normalJobs = $jobs_ids;

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $normalJobs,
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum,
            ];
        }
    }

   /**
     * Store a new booking.
     *
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5; // Immediate time in minutes
        $consumer_type = $user->userMeta->consumer_type;

        // Check if the user is a customer
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            // Validate required fields
            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }

            // Check if the booking is not immediate
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }

            // Set customer phone type
            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

            // Set customer physical type
            $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
            $response['customer_physical_type'] = $data['customer_physical_type'];

            // Set due date and time
            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');

                // Check if the due date is in the past
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in the past";
                    return $response;
                }
            }

            // Set gender and certified values
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }

            // Set job type based on consumer type
            if ($consumer_type == 'rwsconsumer') {
                $data['job_type'] = 'rws';
            } else if ($consumer_type == 'ngo') {
                $data['job_type'] = 'unpaid';
            } else if ($consumer_type == 'paid') {
                $data['job_type'] = 'paid';
            }

            $data['b_created_at'] = date('Y-m-d H:i:s');

            if (isset($due)) {
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }

            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            // Create the job
            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();

            // Add job_for values to data
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator cannot create a booking";
        }

        return $response;
    }

    /**
     * Store the job email and send a notification.
     *
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->first();

        // Update job address, instructions, and town if provided
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }

        $job->save();

        // Determine the email and name for the recipient
        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];

        // Send the email notification
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    /**
     * Convert a job object to data array for sending push notifications.
     *
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [];

        // Save job information to data array
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        // Extract due date and time from the job
        $dueDate = explode(" ", $job->due);
        $data['due_date'] = $dueDate[0];
        $data['due_time'] = $dueDate[1];

        $data['job_for'] = [];

        // Map gender values to readable strings
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        // Map certified values to readable strings
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rättstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * End a job and perform necessary actions.
     *
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];

        // Find the job and calculate session time
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        // Update job details
        $jobDetail->end_at = date('Y-m-d H:i:s');
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;
        $jobDetail->save();

        $user = $jobDetail->user()->get()->first();
        $email = (!empty($jobDetail->user_email)) ? $jobDetail->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $sessionExplode = explode(':', $jobDetail->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        // Send email to the customer
        $data = [
            'user'         => $user,
            'job'          => $jobDetail,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr = $jobDetail->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        // Fire session ended event
        Event::fire(new SessionEnded($jobDetail, ($post_data['userid'] == $jobDetail->user_id) ? $tr->user_id : $jobDetail->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $data = [
            'user'         => $user,
            'job'          => $jobDetail,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Update translator job relation
        $tr->completed_at = $completedDate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Get all potential jobs of a user by user ID.
     *
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        
        if ($translator_type == 'professional') {
            $job_type = 'paid';   // Show all jobs for professionals.
        } else if ($translator_type == 'rwstranslator') {
            $job_type = 'rws';    // For rwstranslator, only show rws jobs.
        } else if ($translator_type == 'volunteer') {
            $job_type = 'unpaid'; // For volunteers, only show unpaid jobs.
        }

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $v) {
            // Checking translator town
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    /**
     * Send push notification to translators based on job and user data.
     *
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();
        $translatorArray = [];      // Suitable translators (no need to delay push)
        $delayedTranslatorArray = []; // Suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) continue;
            $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
            if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // Get all potential jobs of this user
            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id) { // One potential job is the same as the current job
                    $userId = $oneUser->id;
                    $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                    if ($jobForTranslator == 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($userId, $oneJob);
                        if ($jobChecker != 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($oneUser->id)) {
                                $delayedTranslatorArray[] = $oneUser;
                            } else {
                                $translatorArray[] = $oneUser;
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = ($data['immediate'] == 'no') ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msgText = [
            "en" => $msgContents
        ];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayedTranslatorArray, $msgText, $data]);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false); // Send new booking push to suitable translators (not delay)
        $this->sendPushNotificationToSpecificUsers($delayedTranslatorArray, $job->id, $data, $msgText, true); // Send new booking push to suitable translators (need to delay)
    }

    /**
     * Sends SMS to translators and returns the count of translators.
     *
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // Analyze whether it's a phone or physical job; if both, default to phone job
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as a phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Checks if the push notification needs to be delayed for a user.
     *
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Checks if the push notification needs to be sent for a user.
     *
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send OneSignal push notifications to specific users with user tags.
     *
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param string $msg_text
     * @param bool $is_need_delay
     * @param LoggerInterface $logger
     */
    public function sendPushNotificationToSpecificUsers(array $users, int $job_id, array $data, string $msg_text, bool $is_need_delay, LoggerInterface $logger)
    {
        // Get OneSignal app ID and REST API key based on the environment
        $onesignalAppID = env('APP_ENV') === 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = env('APP_ENV') === 'prod'
            ? sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'))
            : sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $client = new Client();
        try {
            $response = $client->post('https://onesignal.com/api/v1/notifications', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $onesignalRestAuthKey,
                ],
                'json' => $fields,
            ]);
            $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response->getBody()]);
        } catch (RequestException $e) {
            $logger->addError('Push notification request failed: ' . $e->getMessage());
        }
    }

    /**
     * Get potential translators for a job.
     *
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        // Determine the translator type based on the job type
        $job_type = $job->job_type;
        $translator_type = '';
        if ($job_type === 'paid') {
            $translator_type = 'professional';
        } elseif ($job_type === 'rws') {
            $translator_type = 'rwstranslator';
        } elseif ($job_type === 'unpaid') {
            $translator_type = 'volunteer';
        }

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            // Set translator levels based on the certification type
            if ($job->certified === 'yes' || $job->certified === 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified === 'law' || $job->certified === 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified === 'health' || $job->certified === 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified === 'normal' || $job->certified === 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified === null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        // Get blacklisted translator IDs
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = $blacklist->pluck('translator_id')->all();

        // Get potential users (translators)
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }

    /**
     * Update a job.
     *
     * @param int $id
     * @param array $data
     * @param User $cuser
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        // Get the current translator
        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', null)->first();
        }

        $log_data = [];
        $langChanged = false;

        // Check if the translator needs to be changed
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        // Check if the due date needs to be changed
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        // Check if the language needs to be changed
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // Check if the status needs to be changed
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        // Log the update
        $this->logger->addInfo(
            'USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
            $log_data
        );

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }

    /**
     * Change the status of a job.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];

                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }

        return ['statusChanged' => $statusChanged, 'log_data' => []];
    }

    /**
     * Change the status of a job to "timedout" and perform corresponding actions.
     *
     * @param $job               The job to update.
     * @param $data              The data containing the new status and other information.
     * @param $changedTranslator Flag indicating if the translator was changed.
     * @return bool              Indicates whether the status change was successful.
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $job->status = $data['status'];

        // Get user information
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            // Update job details and send notification to user
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $jobData = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $jobData, '*');   // Send push notification to all suitable translators

            return true;
        } elseif ($changedTranslator) {
            // Save the job and send acceptance confirmation email to user
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * Change the status of a job to "completed" and perform corresponding actions.
     *
     * @param $job  The job to update.
     * @param $data The data containing the new status and other information.
     * @return bool Indicates whether the status change was successful.
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            // Check if admin comments are provided for timedout status
            if ($data['admin_comments'] == '') {
                return false; // Return false if admin comments are missing
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }


    /**
     * Change the status of a job to "started" and perform corresponding actions.
     *
     * @param $job  The job to update.
     * @param $data The data containing the new status and other information.
     * @return bool Indicates whether the status change was successful.
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '') {
            return false; // Return false if admin comments are missing
        }
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            // Get the user associated with the job
            $user = $job->user()->first();

            if ($data['sesion_time'] == '') {
                return false; // Return false if session time is missing
            }
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);

            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            // Send email to the customer
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            // Send email to the translator for payment information
            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $email = $user->user->email;
            $name = $user->user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->save();
        return true;
    }


   /**
     * Change the status of a job to "pending" and perform corresponding actions.
     *
     * @param $job               The job to update.
     * @param $data              The data containing the new status and other information.
     * @param $changedTranslator Indicates whether the translator has been changed.
     * @return bool              Indicates whether the status change was successful.
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false; // Return false if admin comments are missing for a timedout status
        }
        $job->admin_comments = $data['admin_comments'];

        $user = $job->user()->first();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            // Send email notifications for job acceptance and translator change
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            // Send email notification for status change from pending or assigned
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /**
     * TODO remove method and add service for notification
     * TEMP method
     * Send session start reminder notification.
     *
     * @param $user     The user to send the notification to.
     * @param $job      The job associated with the notification.
     * @param $language The language of the interpretation.
     * @param $due      The due date and time of the interpretation.
     * @param $duration The duration of the interpretation.
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        // TODO: Implement the actual notification service logic

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);

        if ($job->customer_physical_type == 'yes') {
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        } else {
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Change the status of a job to "withdrawafter24" and perform corresponding actions.
     *
     * @param $job  The job to update.
     * @param $data The data containing the new status and other information.
     * @return bool Indicates whether the status change was successful.
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data['status'] == 'timedout') {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') {
                return false; // Return false if admin comments are missing for a timedout status
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * Change the status of a job to "assigned" and perform corresponding actions.
     *
     * @param $job  The job to update.
     * @param $data The data containing the new status and other information.
     * @return bool Indicates whether the status change was successful.
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false; // Return false if admin comments are missing for a timedout status
            }
            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }

                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }

            $job->save();
            return true;
        }
        return false;
    }

    /**
     * Change the translator for a job and perform corresponding actions.
     *
     * @param $current_translator The current translator associated with the job.
     * @param $data               The data containing the new translator information.
     * @param $job                The job to update.
     * @return array              The result of the translator change operation.
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        // Check if there is a current translator or new translator information provided
        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];

            // Check if there is a change in the translator
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                
                // Create a new translator record
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);

                // Cancel the current translator
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                // Update log data
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                // Create a new translator record
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);

                // Update log data
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            }

            // Return the result of the translator change operation
            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * Change the due date for a job and perform corresponding actions.
     *
     * @param $old_due The old due date.
     * @param $new_due The new due date.
     * @return array   The result of the due date change operation.
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;

        // Check if there is a change in the due date
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * Send notifications for changed translator in a job.
     *
     * @param $job                The job object.
     * @param $current_translator The current translator object.
     * @param $new_translator     The new translator object.
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        // Get user details
        $user = $job->user()->first();
        $name = $user->name;

        // Determine the recipient email address
        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        // Prepare email subject
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

        // Prepare email data
        $data = [
            'user' => $user,
            'job'  => $job
        ];

        // Send notification to customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        // Send notification to old translator, if applicable
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        // Send notification to new translator
        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * Send notifications for changed date in a job.
     *
     * @param $job      The job object.
     * @param $old_time The old time value.
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        // Get user details
        $user = $job->user()->first();
        $name = $user->name;

        // Determine the recipient email address
        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        // Prepare email subject
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        // Prepare email data
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        // Send notification to customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        // Get details of the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        // Prepare email data for the translator
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];

        // Send notification to the assigned translator
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Send notifications for changed language in a job.
     *
     * @param $job      The job object.
     * @param $old_lang The old language value.
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        // Get user details
        $user = $job->user()->first();
        $name = $user->name;

        // Determine the recipient email address
        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        // Prepare email subject
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        // Prepare email data
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        // Send notification to customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        // Get details of the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        // Send notification to the assigned translator
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Send a push notification for an expired job.
     *
     * @param $job  The job object.
     * @param $user The user object.
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';

        // Fetch the language from the job ID
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        // Prepare the message text for the notification
        $msg_text = [
            'en' => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        // Check if the user needs to receive push notifications
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }


    /**
     * Send a notification for job cancellation by admin.
     *
     * @param $job_id The ID of the job.
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        
        // Prepare job information for sending push notification
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];

        // Set job target based on gender and certified status
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        
        $this->sendNotificationTranslator($job, $data, '*'); // Send push notification to all suitable translators
    }

    /**
     * Send a notification for session start reminder.
     *
     * @param $user The user to send the notification to.
     * @param $job The job associated with the notification.
     * @param $language The language of the session.
     * @param $due The due date of the session.
     * @param $duration The duration of the session.
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        // Determine the message text based on the customer's physical type
        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                'en' => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];
        } else {
            $msg_text = [
                'en' => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Generate a user_tags string from an array of users for creating OneSignal notifications.
     *
     * @param $users The array of users.
     * @return string The generated user_tags string.
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "["; // Start of the user_tags string
        $first = true; // Flag to track the first user

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},'; // Add OR operator between users
            }

            // Append user information as a JSON object in the user_tags string
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        $user_tags .= ']'; // End of the user_tags string
        return $user_tags;
    }

    /**
     * Accepts a job by a user.
     *
     * @param $data The data containing the job ID.
     * @param $user The user accepting the job.
     * @return array The response indicating the status of the job acceptance.
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        // Check if the translator is already booked for another job at the same time
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }

                $data = [
                    'user' => $user,
                    'job'  => $job
                ];

                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }

            // TODO: Add flash message here.

            $jobs = $this->getPotentialJobs($cuser);
            $response = [
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success'
            ];
        } else {
            $response = [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        return $response;
    }

    /**
     * Accepts a job with the specified job ID.
     *
     * @param $job_id The ID of the job to accept.
     * @param $cuser The user accepting the job.
     * @return array The response indicating the status of the job acceptance.
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        // Check if the translator is already booked for another job at the same time
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = [];
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                ];

                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }

                // Booking accepted successfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking at the same time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }


    /**
     * Cancels a job via AJAX request.
     *
     * @param $data The data containing the job ID.
     * @param $user The user cancelling the job.
     * @return array The response indicating the status of the job cancellation.
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];

        /*
        @todo
        Add 24-hour logging here.
        If the cancellation is made before 24 hours before the booking time,
        the supplier will be informed and the flow will end.
        If the cancellation is made within 24 hours,
        the translator will be informed and the customer will be charged
        an additional fee as if the session was executed.
        */

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();

            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $data = [];
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . ' tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id)); // Send Session Cancel Push to Translator
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $data = [];
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . ' tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = [$customer];
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id)); // Send Session Cancel Push to Customer
                    }
                }

                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();

                // Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id); // Send push notification to all suitable translators

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
            }
        }

        return $response;
    }

    /**
     * Retrieves potential jobs for the given translator based on their type.
     *
     * @param $cuser The translator user.
     * @return array The potential jobs for the translator.
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;

        if ($translator_type == 'professional') {
            $job_type = 'paid'; /* Show all jobs for professionals. */
        } elseif ($translator_type == 'rwstranslator') {
            $job_type = 'rws'; /* For rwstranslator, only show rws jobs. */
        } elseif ($translator_type == 'volunteer') {
            $job_type = 'unpaid'; /* For volunteers, only show unpaid jobs. */
        }

        $languages = UserLanguages::where('user_id', $cuser->id)->get();
        $userlanguage = $languages->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    /**
     * Ends a job and performs necessary actions.
     *
     * @param $post_data The data related to the job to be ended.
     * @return array The response status.
     */
    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    /**
     * Handles the scenario when the customer does not call for the job.
     *
     * @param array $post_data The data related to the job.
     * @return array The response status.
     */
    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];

        // Retrieve the job details with associated translator job relationship
        $job = Job::with('translatorJobRel')->find($jobid);

        $duedate = $job->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        // Update the job status and completion details
        $job->end_at = $completeddate;
        $job->status = 'not_carried_out_customer';

        $translatorJobRel = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorJobRel->completed_at = $completeddate;
        $translatorJobRel->completed_by = $translatorJobRel->user_id;

        // Save the changes to the job and translator job relationship
        $job->save();
        $translatorJobRel->save();

        $response['status'] = 'success';
        return $response;
    }

    /**
     * Get all jobs based on the request parameters.
     *
     * @param Request $request The HTTP request object.
     * @param int|null $limit The limit for pagination, or null for default.
     * @return mixed The collection or pagination of jobs.
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            // Check for feedback
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            // Filter by ID
            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id'])) {
                    $allJobs->whereIn('id', $requestdata['id']);
                } else {
                    $allJobs->where('id', $requestdata['id']);
                }
                $requestdata = array_only($requestdata, ['id']);
            }

            // Filter by language
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            // Filter by status
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            // Filter by expired_at
            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }

            // Filter by will_expire_at
            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }

            // Filter by customer_email
            if (isset($requestdata['customer_email']) && is_array($requestdata['customer_email']) && count($requestdata['customer_email']) > 0) {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }

            // Filter by translator_email
            if (isset($requestdata['translator_email']) && is_array($requestdata['translator_email']) && count($requestdata['translator_email']) > 0) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            // Filter by filter_timetype
            if (isset($requestdata['filter_timetype'])) {
                if ($requestdata['filter_timetype'] == "created") {
                    if (isset($requestdata['from']) && $requestdata['from'] != "") {
                        $allJobs->where('created_at', '>=', $requestdata["from"]);
                    }
                    if (isset($requestdata['to']) && $requestdata['to'] != "") {
                        $to = $requestdata["to"] . " 23:59:00";
                        $allJobs->where('created_at', '<=', $to);
                    }
                    $allJobs->orderBy('created_at', 'desc');
                } elseif ($requestdata['filter_timetype'] == "due") {
                    if (isset($requestdata['from']) && $requestdata['from'] != "") {
                        $allJobs->where('due', '>=', $requestdata["from"]);
                    }
                    if (isset($requestdata['to']) && $requestdata['to'] != "") {
                        $to = $requestdata["to"] . " 23:59:00";
                        $allJobs->where('due', '<=', $to);
                    }
                    $allJobs->orderBy('due', 'desc');
                }
            }

            // Filter by job_type
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            // Filter by physical and phone
            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical']);
                $allJobs->where('ignore_physical', 0);
            }
            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);
                if (isset($requestdata['physical'])) {
                    $allJobs->where('ignore_physical_phone', 0);
                }
            }

            // Filter by flagged
            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            // Filter by distance
            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            // Filter by salary
            if (isset($requestdata['salary']) && $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            // Filter by consumer_type
            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            // Filter by booking_type
            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                }
                if ($requestdata['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            $allJobs = Job::query();

            // Filter by id
            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            // Filter by consumer_type
            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }

            // Filter by feedback
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            // Filter by lang
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            // Filter by status
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            // Filter by job_type
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            // Filter by customer_email
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }

            // Filter by filter_timetype
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            } elseif (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }

    /**
     * Retrieve alerts and related data
     *
     * @return array
     */
    public function alerts()
    {
        // Fetch all jobs
        $jobs = Job::all();
        
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        // Iterate through jobs to filter session jobs
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            
            // Calculate session duration in minutes
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                // Check if session duration is at least equal to the job duration
                if ($diff[$i] >= $job->duration) {
                    // Check if session duration is at least twice the job duration
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        // Extract job IDs from session jobs
        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        // Retrieve languages
        $languages = Language::where('active', '1')->orderBy('language')->get();
        
        // Get request data
        $requestdata = Request::all();
        
        // Get all customer emails
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->toArray();
        
        // Get all translator emails
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->toArray();

        // Retrieve the current user
        $cuser = Auth::user();
        
        // Get consumer type metadata for the current user
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            // Retrieve all jobs with their associated languages
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId);

            // Apply filters if provided
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->toArray();
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
            }
            
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId)
                ->orderBy('jobs.created_at', 'desc');

            // Paginate the results
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }

    /**
     * Retrieve expired bookings that are not accepted
     *
     * @return array
     */
    public function bookingExpireNoAccepted()
    {
        // Retrieve active languages
        $languages = Language::where('active', '1')->orderBy('language')->get();
        
        // Get request data
        $requestdata = Request::all();
        
        // Get all customer emails
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->toArray();
        
        // Get all translator emails
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->toArray();

        // Retrieve the current user
        $cuser = Auth::user();
        
        // Get consumer type metadata for the current user
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            // Retrieve all jobs with their associated languages
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);

            // Apply filters if provided
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->toArray();
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            
            // Select necessary columns and apply additional conditions
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            
            // Paginate the results
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }

    /**
     * Ignore the expiring job by ID.
     *
     * @param  int  $id  The ID of the job to ignore.
     * @return array     An array indicating the success of the operation.
     */
    public function ignoreExpiring(int $id): array
    {
        // Find the job by ID
        $job = Job::find($id);

        // Update the 'ignore' attribute to 1
        $job->ignore = 1;

        // Save the changes
        $job->save();

        return ['success' => 'Changes saved'];
    }

    /**
     * Ignore the expired job by ID.
     *
     * @param  int  $id  The ID of the job to ignore.
     * @return array     An array indicating the success of the operation.
     */
    public function ignoreExpired(int $id): array
    {
        // Find the job by ID
        $job = Job::find($id);

        // Update the 'ignore_expired' attribute to 1
        $job->ignore_expired = 1;

        // Save the changes
        $job->save();

        return ['success' => 'Changes saved'];
    }

    /**
     * Ignore the throttle by ID.
     *
     * @param  int  $id  The ID of the throttle to ignore.
     * @return array     An array indicating the success of the operation.
     */
    public function ignoreThrottle(int $id): array
    {
        // Find the throttle by ID
        $throttle = Throttles::find($id);

        // Update the 'ignore' attribute to 1
        $throttle->ignore = 1;

        // Save the changes
        $throttle->save();

        return ['success' => 'Changes saved'];
    }

    /**
     * Reopen a job.
     *
     * @param  array  $request  The request data containing 'jobid' and 'userid'.
     * @return array            An array indicating the success of the operation.
     */
    public function reopen(array $request): array
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now()),
        ];

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;

            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        $translator = Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ['success' => 'Tolk cancelled!'];
        } else {
            return ['error' => 'Please try again!'];
        }
    }

    /**
     * Convert the number of minutes to hour and minute format.
     *
     * @param  int     $time    The number of minutes.
     * @param  string  $format  The format string for the result.
     * @return string           The formatted time in hours and minutes.
     */
    private function convertToHoursMins(int $time, string $format = '%02dh %02dmin'): string
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
}