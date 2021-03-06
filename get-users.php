<?php
set_time_limit(0);
ini_set('memory_limit', '10240M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Los_Angeles'); //make sure to set the expected timezone
require './vendor/autoload.php';
require './config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Enum\NullableBoolean;
use Kaltura\Client\Enum\SearchOperatorType;
use Kaltura\Client\ILogger;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\Enum\UserOrderBy;
use Kaltura\Client\Enum\UserStatus;
use Kaltura\Client\Plugin\Metadata\Type\MetadataSearchItem;
use Kaltura\Client\Type\FilterPager;
use Kaltura\Client\Type\SearchCondition;
use Kaltura\Client\Type\UserFilter;

class GetUsersUtil implements ILogger
{
	const PARENT_PARTNER_IDS = array();
	const SERVICE_URL = 'https://cdnapisec.kaltura.com'; //The base URL to the Kaltura server API endpoint
	const KS_EXPIRY_TIME = 86000; // Kaltura session length. Please note the script may run for a while so it mustn't be too short.
	const DEBUG_PRINTS = true; //Set to true if you'd like the script to output logging to the console (this is different from the KalturaLogger)
	const CYCLE_SIZES = 300; // Determines how many entries will be processed in each multi-request call - set it to whatever number works best for your server.
	const ERROR_LOG_FILE = './kaltura_logger.log'; //The name of the KalturaLogger export file
	const SHOULD_LOG = false;
	const USER_STATUS_PRE_REGISTERED = 'Pre-registered';
	const USER_STATUS_REGISTERED = 'Registered';
	const USER_STATUS_UN_REGISTERED = 'Un-registered';
	const USER_STATUS_ATTENDED = 'Attended';

	private $excelColumnHeaderFormats = array(
		'id' => ['prettyName' => 'User ID', 'defaultVal' => '', 'fieldType' => ''],
		'email' => ['prettyName' => 'Email', 'defaultVal' => '', 'fieldType' => ''],
		'firstName' => ['prettyName' => 'First Name', 'defaultVal' => '', 'fieldType' => ''],
		'lastName' => ['prettyName' => 'Last Name', 'defaultVal' => '', 'fieldType' => ''],
		'company' => ['prettyName' => 'Company', 'defaultVal' => '', 'fieldType' => ''],
		'country' => ['prettyName' => 'Country', 'defaultVal' => '', 'fieldType' => ''],
		'industry' => ['prettyName' => 'Industry', 'defaultVal' => '', 'fieldType' => ''],
		'jobRole' => ['prettyName' => 'Job Role', 'defaultVal' => '', 'fieldType' => ''],
		'interests' => ['prettyName' => 'Interests', 'defaultVal' => '', 'fieldType' => ''],
		'apps' => ['prettyName' => 'Development Areas', 'defaultVal' => '', 'fieldType' => ''],
		'gender' => ['prettyName' => 'Gender', 'defaultVal' => '', 'fieldType' => ''],
		'race' => ['prettyName' => 'Ethnicity', 'defaultVal' => '', 'fieldType' => ''],
		'nl1' => ['prettyName' => 'Marketing Opt In', 'defaultVal' => false, 'fieldType' => ''],
		'nl2' => ['prettyName' => 'Enterprise Opt In', 'defaultVal' => false, 'fieldType' => ''],
		'nl3' => ['prettyName' => 'Developer Opt In', 'defaultVal' => false, 'fieldType' => ''],
		'nvid' => ['prettyName' => 'NVID', 'defaultVal' => 'no-nvid', 'fieldType' => ''],
		'ncid' => ['prettyName' => 'NCID', 'defaultVal' => 'no-ncid', 'fieldType' => ''],
		'status' => ['prettyName' => 'Registration Status', 'defaultVal' => '', 'fieldType' => ''],
		'statusUpdateTime' => ['prettyName' => 'Registration Status Update', 'defaultVal' => '', 'fieldType' => 'date'],
		'createdAt' => ['prettyName' => 'Created At', 'defaultVal' => '', 'fieldType' => 'date'],
		'updatedAt' => ['prettyName' => 'Last Updated At', 'defaultVal' => '', 'fieldType' => 'date'],
		'realregstatus' => ['prettyName' => 'Detailed Registration Status', 'defaultVal' => '', 'fieldType' => '']
	);

	public function run($pid, $secret, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $addEmailAttachment, $reportDlBaseUrl, $emailRecipients2)
	{
		$xslxfile = './user-profiles-' . $pid . '.xlsx';

		//Reset the log file:
		if (GetUsersUtil::SHOULD_LOG) {
			$errline = "Here you'll find the log form the Kaltura Client library, in case issues occur you can use this file to investigate and report errors.";
			file_put_contents(GetUsersUtil::ERROR_LOG_FILE, $errline);
		}
		$kConfig = new KalturaConfiguration($pid);
		$kConfig->setServiceUrl(GetUsersUtil::SERVICE_URL);
		$kConfig->setLogger($this);
		$this->client = new KalturaClient($kConfig);
		$this->ks = $this->client->session->start($secret, 'users-xls-export', SessionType::ADMIN, $pid, GetUsersUtil::KS_EXPIRY_TIME, 'list:*,disableentitlement,*');
		$this->client->setKs($this->ks);

		$userRoleMetadataProfileId = 14645193;

		$foundUsers = array();
		$lastCreatedAt = -1;
		$foundUsers = array();
		$lastCreatedAt = -1;
		$filter = new UserFilter();
		$filter->orderBy = UserOrderBy::CREATED_AT_ASC;
		$filter->createdAtGreaterThanOrEqual = $lastCreatedAt;
		$filter->isAdminEqual = NullableBoolean::FALSE_VALUE;
		$filter->advancedSearch = new MetadataSearchItem();
		$filter->advancedSearch->metadataProfileId = $userRoleMetadataProfileId;
		$filter->advancedSearch->type = SearchOperatorType::SEARCH_AND;
		$filter->advancedSearch->items = [];
		$filter->advancedSearch->items[0] = new SearchCondition();
		$filter->advancedSearch->items[0]->field = "/*[local-name()='metadata']/*[local-name()='role']";
		$filter->advancedSearch->items[0]->value = "*";
		$pager = new FilterPager();
		$pager->pageSize = 500;
		$pager->pageIndex = 1;

		$searchResults = $this->client->getUserService()->listAction($filter, $pager);
		while ($searchResults->totalCount > 0) {
			foreach ($searchResults->objects as $user) {
				$userId = $user->id;
				$hasRegInfo = isset($user->registrationInfo) && $user->registrationInfo != null && $user->registrationInfo != '';
				$userProfile = $hasRegInfo ? json_decode($user->registrationInfo) : null;
				$hasAttInfo = isset($user->attendanceInfo) && $user->attendanceInfo != null && $user->attendanceInfo != '';
				$userAttendance = $hasAttInfo ? json_decode($user->attendanceInfo) : null;
				if ($userAttendance != null) {
					/*
					user.status = blocked + attendanceInfo = preRegistered => unconfirmed user
					user.status = blocked + attendanceInfo = unregistered => user unregistered
					user.status = blocked + attendanceInfo = registered => user blocked by admin
					user status = active should only be in users in attendanceInfo registered or attended.
					*/
					$realregstatus = '';
					if ($user->status == UserStatus::BLOCKED && $userAttendance->status == self::USER_STATUS_PRE_REGISTERED) {
						$realregstatus = 'Waiting Email Verification';
					} elseif ($user->status == UserStatus::BLOCKED && $userAttendance->status == self::USER_STATUS_UN_REGISTERED) {
						$realregstatus = 'Un-Registered User';
					} elseif ($user->status == UserStatus::BLOCKED && $userAttendance->status == self::USER_STATUS_REGISTERED) {
						$realregstatus = 'User Blocked by Admin';
					} else {
						$realregstatus = $userAttendance->status;
					}
					// only add to the report if the user was not deleted
					if ($realregstatus != 'Un-Registered User' && $realregstatus != 'User Blocked by Admin') {
						if (!isset($foundUsers[$userId]) || $foundUsers[$userId] == null) {
							$foundUsers[$userId] = array();
						} else {
							continue; //user already exists
						}
						foreach ($this->excelColumnHeaderFormats as $columnName => $columnSettings) {
							if ($columnName == 'realregstatus') {
								$foundUsers[$userId]['realregstatus'] = $realregstatus;
								continue;
							}
							if (isset($user->{$columnName}) && $columnName != 'status') { //take status from registrationInfo instead of user status
								$foundUsers[$userId][$columnName] = $user->{$columnName};
							} elseif (isset($userProfile->{$columnName})) {
								$fvalue = $userProfile->{$columnName};
								if (is_array($fvalue)) {
									$fvalue = implode(',', $fvalue);
								}
								$foundUsers[$userId][$columnName] = $fvalue;
							} elseif (isset($userAttendance->{$columnName})) {
								$fvalue = $userAttendance->{$columnName};
								if (is_array($fvalue)) {
									$fvalue = implode(',', $fvalue);
								}
								$foundUsers[$userId][$columnName] = $fvalue;
							} else {
								$foundUsers[$userId][$columnName] = $columnSettings['defaultVal'];
							}
							if (isset($columnSettings['fieldType']) && $columnSettings['fieldType'] == 'date' && $foundUsers[$userId][$columnName] != '') {
								$foundUsers[$userId][$columnName] = $this->convertTimestamp2Excel($foundUsers[$userId][$columnName]);
							}
						}
						if ($realregstatus != 'Registered' && $realregstatus != 'Attended') print "user: {$user->id}, {$user->email}, status: {$realregstatus}\n";
					}
				}
				$lastCreatedAt = $user->createdAt;
			}
			$filter->createdAtGreaterThanOrEqual = $lastCreatedAt;
			usleep(250000);
			$searchResults = $this->client->getUserService()->listAction($filter, $pager);
		}
		$totalRegisteredUsers = count($foundUsers);
		print "found total {$totalRegisteredUsers} users.\n";

		$date = date("MdY-HiT");

		$writer = WriterEntityFactory::createXLSXWriter();
		$writer->openToFile($xslxfile);
		//header:
		$style = (new StyleBuilder())
           ->setFontBold()
           ->build();
		$headerArr = array();
		foreach ($this->excelColumnHeaderFormats as $columnName => $columnSettings) {
			$headerCell = WriterEntityFactory::createCell($columnSettings['prettyName']);
			array_push($headerArr, $headerCell);
		}
		$headerRow = WriterEntityFactory::createRow($headerArr, $style);
		$writer->addRow($headerRow);

		//data:
		$styleDate = (new StyleBuilder())->setFormat('[$-en-US]m/d/yy h:mm AM/PM;@')->build();
		foreach ($foundUsers as $user_id => $user) {
			$rowArr = array();
			foreach ($user as $userprofile_field => $userprofile_value) {
                if ($this->excelColumnHeaderFormats[$userprofile_field]['fieldType'] == 'date') {
                    $rowArr[] = WriterEntityFactory::createCell($userprofile_value, $styleDate);
                } else {
					$rowArr[] = WriterEntityFactory::createCell($userprofile_value);
				}
			}
			$bodyRow = WriterEntityFactory::createRow($rowArr);
			$writer->addRow($bodyRow);
		}
		
		$writer->close();

		echo 'Successfully exported data!' . PHP_EOL;
		echo 'File name: ' . $xslxfile . PHP_EOL;
		$filepath = $addEmailAttachment ? $xslxfile : null;
		$this->sendSESmail($pid, $date, $filepath, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $reportDlBaseUrl);
		$this->sendSESmail($pid, $date, $filepath, $emailSender, $emailRecipients2, $usernameSmtp, $passwordSmtp, $reportDlBaseUrl);
	}

	private function sendSESmail($pid, $date, $filepath, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $reportDlBaseUrl)
	{
		$sender = $emailSender;
		$senderName = 'Kaltura Users Report';
		$configurationSet = null;
		$host = 'email-smtp.us-east-1.amazonaws.com';
		$port = 587;
		$subject = 'Kaltura Users Report (' . $pid . ') - ' . $date;
		if ($filepath != '' && $filepath != null) {
			$bodyText = "Kaltura Virtual Event Registrations Report\r\nFor account ID: {$pid}\r\nPlease find attached the recently-updated users reprot, as of {$date}";
			$bodyHtml =  "<h1>Kaltura Virtual Event Registrations Report</h1><h2>For account ID: {$pid}</h2><p>Please find attached the recently-updated users reprot, as of {$date}</p>";
		} else {
			$bodyText = "Kaltura Virtual Event Registrations Report\r\nFor account ID: {$pid}\r\nPlease download ( from: {$reportDlBaseUrl}{$pid} ) the recently-updated users reprot, as of {$date}";
			$bodyHtml =  "<h1>Kaltura Virtual Event Registrations Report</h1><h2>For account ID: {$pid}</h2><p>Please <a href=\"{$reportDlBaseUrl}{$pid}\" download style=\"font-weight: bold;\">download the recently-updated users reprot</a>, as of {$date}</p>";
		}
		$mail = new PHPMailer(true);
		try {
			$mail->isSMTP();
			$mail->setFrom($sender, $senderName);
			$mail->Username   = $usernameSmtp;
			$mail->Password   = $passwordSmtp;
			$mail->Host       = $host;
			$mail->Port       = $port;
			$mail->SMTPAuth   = true;
			$mail->SMTPSecure = 'tls';
			$mail->addCustomHeader('X-SES-CONFIGURATION-SET', $configurationSet);
			$addresses = explode(';', $emailRecipients);
			foreach ($addresses as $addy) {
				$mail->addAddress($addy);
			}
			$mail->isHTML(true);
			$mail->Subject    = $subject;
			$mail->Body       = $bodyHtml;
			$mail->AltBody    = $bodyText;
			if ($filepath != '' && $filepath != null) $mail->addAttachment($filepath);
			$mail->Send();
			echo "Email sent!", PHP_EOL;
		} catch (Exception $e) {
			echo "An error occurred. {$e->errorMessage()}", PHP_EOL; //Catch errors from PHPMailer.
		} catch (Exception $e) {
			echo "Email not sent. {$mail->ErrorInfo}", PHP_EOL; //Catch errors from Amazon SES.
		}
	}

	private function convertTimestamp2Excel($input)
	{
		$output = 25569 + (($input + date('Z', $input)) / 86400);
		return $output;
	}
	public function log($message)
	{
		if (GetUsersUtil::SHOULD_LOG) {
			$errline = date('Y-m-d H:i:s') . ' ' .  $message . "\n";
			file_put_contents(GetUsersUtil::ERROR_LOG_FILE, $errline, FILE_APPEND);
		}
	}
	private function presistantApiRequest($service, $actionName, $paramsArray, $numOfAttempts)
	{
		$attempts = 0;
		$lastError = null;
		do {
			try {
				$response = call_user_func_array(
					array(
						$service,
						$actionName
					),
					$paramsArray
				);
				if ($response === false) {
					$this->log("Error Processing API Action: " . $actionName);
					throw new Exception("Error Processing API Action: " . $actionName, 1);
				}
			} catch (Exception $e) {
				$lastError = $e;
				++$attempts;
				sleep(10);
				continue;
			}
			break;
		} while ($attempts < $numOfAttempts);
		if ($attempts >= $numOfAttempts) {
			$this->log('======= API BREAKE =======' . PHP_EOL);
			$this->log('Message: ' . $lastError->getMessage() . PHP_EOL);
			$this->log('Last Kaltura client headers:' . PHP_EOL);
			$this->log(
				print_r(
					$this
						->client
						->getResponseHeaders()
				)
			);
			$this->log('===============================');
		}
		return $response;
	}
	public function getFullListOfKalturaObject($filter, $listService, $idField = 'id', $valueFields = null, $printProgress = false, $stopOnCreatedAtDate = false, $objectName = null)
	{
		$serviceName = get_class($listService);
		$filter->orderBy = '+createdAt';
		$filter->createdAtGreaterThanOrEqual = null;
		$pager = new FilterPager();
		$pager->pageSize = GetUsersUtil::CYCLE_SIZES;
		$pager->pageIndex = 1;
		$lastCreatedAt = 0;
		$lastObjectIds = '';
		$reachedLastObject = false;
		$allObjects = array();
		$count = 0;
		$totalCount = 0;
		$countAvailable = method_exists($listService, 'count');
		if ($countAvailable) {
			if ($stopOnCreatedAtDate && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1) {
				$filter->createdAtGreaterThanOrEqual = $this->stopDateForCreatedAtFilter;
			}
			$totalCount = $this->presistantApiRequest($listService, 'count', array($filter), 5);
			$filter->createdAtGreaterThanOrEqual = null;
		}
		// if this filter doesn't have idNotIn - we need to find the highest totalCount
		// this is a workaround hack due to a bug in how categoryEntry list action calculates totalCount
		/*
		if (!property_exists($filter, 'idNotIn')) {
			$temppager = new FilterPager();
			$temppager->pageSize = GetUsersUtil::CYCLE_SIZES;
			$temppager->pageIndex = 1;
			$result = $this->presistantApiRequest($listService, 'listAction', array($filter, $temppager), 5);
			while (isset($result->objects) && count($result->objects) > 0) {
				$totalCount = max($totalCount, $result->totalCount);
				++$temppager->pageIndex;
				$result = $this->presistantApiRequest($listService, 'listAction', array($filter, $temppager), 5);
			}
		}*/
		$totalObjects2Get = $totalCount;
		while (!$reachedLastObject) {
			if ($lastCreatedAt != 0) {
				$filter->createdAtGreaterThanOrEqual = $lastCreatedAt;
			}
			if (
				$stopOnCreatedAtDate == true && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1 &&
				$totalObjects2Get <= GetUsersUtil::CYCLE_SIZES
			) {
				$filter->createdAtGreaterThanOrEqual = $this->stopDateForCreatedAtFilter;
			}

			if ($lastObjectIds != '' && property_exists($filter, 'idNotIn'))
				$filter->idNotIn = $lastObjectIds;

			$filteredListResult = $this->presistantApiRequest($listService, 'listAction', array($filter, $pager), 5);

			if ($totalCount == 0) $totalCount = $filteredListResult->totalCount;

			$resultsCount = count($filteredListResult->objects);

			if ($resultsCount == 0 || $totalCount <= $count) {
				$reachedLastObject = true;
				break;
			}

			foreach ($filteredListResult->objects as $obj) {
				if ($count < $totalCount) {
					if ($valueFields == null) {
						$allObjects[$obj->{$idField}] = $obj;
					} elseif (is_string($valueFields)) {
						if (substr($valueFields, -1) == '*') {
							$valfield = substr($valueFields, 0, -1);
							if (!isset($allObjects[$obj->{$idField}]))
								$allObjects[$obj->{$idField}] = array();
							$allObjects[$obj->{$idField}][] = $obj->{$valfield};
						} else {
							$allObjects[$obj->{$idField}] = $obj->{$valueFields};
						}
					} elseif (is_array($valueFields)) {
						if (!isset($allObjects[$obj->{$idField}]))
							$allObjects[$obj->{$idField}] = array();
						foreach ($valueFields as $field) {
							switch ($field) {
								case 'objectType':
									$allObjects[$obj->{$idField}]['objectType'] = get_class($obj);
									break;
								case 'status':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['status'] = GetUsersUtil::getENUMString($objectName . 'Status', $obj->{$field});
									break;
								case 'mediaType':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['mediaType'] = GetUsersUtil::getENUMString('MediaType', $obj->{$field});
									if ($allObjects[$obj->{$idField}]['mediaType'] == 'LIVE_STREAM_FLASH')
										$allObjects[$obj->{$idField}]['mediaType'] = 'LIVE_STREAM';
									break;
								case 'type':
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}]['type'] = GetUsersUtil::getENUMString($objectName . 'Type', $obj->{$field});
									break;
								default:
									if (isset($obj->{$field}))
										$allObjects[$obj->{$idField}][$field] = $obj->{$field};
							}
						}
					}

					if ($lastCreatedAt < $obj->createdAt) $lastObjectIds = '';

					$lastCreatedAt = $obj->createdAt;

					if (
						$stopOnCreatedAtDate && $this->stopDateForCreatedAtFilter != null && $this->stopDateForCreatedAtFilter > -1 &&
						$lastCreatedAt < $this->stopDateForCreatedAtFilter
					) {
						$reachedLastObject = true;
						break;
					}

					if ($lastObjectIds != '') $lastObjectIds .= ',';
					$lastObjectIds .= $obj->{$idField};
				} else {
					$reachedLastObject = true;
					break;
				}
			}

			$count += $resultsCount;
		}

		return $allObjects;
	}
	public static function getENUMString($enumName, $value2search)
	{
		$oClass = new ReflectionClass('Kaltura\Client\Enum\\' . $enumName);
		$statuses = $oClass->getConstants();
		foreach ($statuses as $key => $value) {
			if ($value == $value2search)
				return $key;
		}
	}
}
class ExecutionTime
{
	//credit: https://stackoverflow.com/a/22885011
	private $startTime;
	private $endTime;

	private $time_start     =   0;
	private $time_end       =   0;
	private $time           =   0;

	public function start()
	{
		$this->startTime = getrusage();
		$this->time_start = microtime(true);
	}

	public function end()
	{
		$this->endTime = getrusage();
		$this->time_end = microtime(true);
	}

	public function totalRunTime()
	{
		$this->time = round($this->time_end - $this->time_start);
		$minutes = floor($this->time / 60); //only minutes
		$seconds = $this->time % 60; //remaining seconds, using modulo operator
		return "Total script execution time: minutes:$minutes, seconds:$seconds";
	}

	private function runTime($ru, $rus, $index)
	{
		return ($ru["ru_$index.tv_sec"] * 1000 + intval($ru["ru_$index.tv_usec"] / 1000))
			-  ($rus["ru_$index.tv_sec"] * 1000 + intval($rus["ru_$index.tv_usec"] / 1000));
	}

	public function __toString()
	{
		return $this->totalRunTime() . PHP_EOL . "This process used " . $this->runTime($this->endTime, $this->startTime, "utime") .
			" ms for its computations\nIt spent " . $this->runTime($this->endTime, $this->startTime, "stime") .
			" ms in system calls\n";
	}
}
$executionTime = new ExecutionTime();
$executionTime->start();
exec("rm -f user-profiles-*");
foreach (PARENT_PARTNER_IDS as $pid => $secret) {
	$instance = new GetUsersUtil();
	$instance->run($pid, $secret, EMAIL_SENDER, EMAIL_RECIPIENTS1, SMTP_USERNAME, SMTP_PASSWORD, SHOULD_SEND_EMAIL_ATTACHMENTS, REPORT_BASE_URL, EMAIL_RECIPIENTS2);
	unset($instance);
}
$executionTime->end();
echo $executionTime;
