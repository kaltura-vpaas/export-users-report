<?php
set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Los_Angeles'); //make sure to set the expected timezone
require '/home/ubuntu/export-users-report/vendor/autoload.php';
require '/home/ubuntu/export-users-report/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\AdvancedValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\ILogger;
use Kaltura\Client\Enum\{SessionType, UserStatus, UserType};
use Kaltura\Client\Type\{FilterPager, UserFilter};

class GetUsersUtil implements ILogger
{
	const PARENT_PARTNER_IDS = array();
	const SERVICE_URL = 'https://cdnapisec.kaltura.com'; //The base URL to the Kaltura server API endpoint
	const KS_EXPIRY_TIME = 86000; // Kaltura session length. Please note the script may run for a while so it mustn't be too short.
	const DEBUG_PRINTS = true; //Set to true if you'd like the script to output logging to the console (this is different from the KalturaLogger)
	const CYCLE_SIZES = 400; // Determines how many entries will be processed in each multi-request call - set it to whatever number works best for your server.
	const ERROR_LOG_FILE = '/home/ubuntu/export-users-report/kaltura_logger.log'; //The name of the KalturaLogger export file
	const SHOULD_LOG = false;

	private $excelColumnHeaderFormats = array(
		'id' => ['prettyName' => 'User ID', 'defaultVal' => '', 'excelFormat' => ''],
		'email' => ['prettyName' => 'Email', 'defaultVal' => '', 'excelFormat' => ''],
		'firstName' => ['prettyName' => 'First Name', 'defaultVal' => '', 'excelFormat' => ''],
		'lastName' => ['prettyName' => 'Last Name', 'defaultVal' => '', 'excelFormat' => ''],
		'company' => ['prettyName' => 'Company', 'defaultVal' => '', 'excelFormat' => ''],
		'country' => ['prettyName' => 'Country', 'defaultVal' => '', 'excelFormat' => ''],
		'industry' => ['prettyName' => 'Industry', 'defaultVal' => '', 'excelFormat' => ''],
		'jobRole' => ['prettyName' => 'Job Role', 'defaultVal' => '', 'excelFormat' => ''],
		'interests' => ['prettyName' => 'Interests', 'defaultVal' => '', 'excelFormat' => ''],
		'apps' => ['prettyName' => 'Development Areas', 'defaultVal' => '', 'excelFormat' => ''],
		'gender' => ['prettyName' => 'Gender', 'defaultVal' => '', 'excelFormat' => ''],
		'race' => ['prettyName' => 'Ethnicity', 'defaultVal' => '', 'excelFormat' => ''],
		'nl1' => ['prettyName' => 'Marketing Opt In', 'defaultVal' => false, 'excelFormat' => ''],
		'nl2' => ['prettyName' => 'Enterprise Opt In', 'defaultVal' => false, 'excelFormat' => ''],
		'nl3' => ['prettyName' => 'Developer Opt In', 'defaultVal' => false, 'excelFormat' => ''],
		'nvid' => ['prettyName' => 'NVID', 'defaultVal' => 'no-nvid', 'excelFormat' => ''],
		'ncid' => ['prettyName' => 'NCID', 'defaultVal' => 'no-ncid', 'excelFormat' => ''],
		'status' => ['prettyName' => 'Registration Status', 'defaultVal' => '', 'excelFormat' => ''],
		'statusUpdateTime' => ['prettyName' => 'Registration Status Update', 'defaultVal' => '', 'excelFormat' => '[$-en-US]m/d/yy h:mm AM/PM;@', 'fieldType' => 'date'],
		'createdAt' => ['prettyName' => 'Created At', 'defaultVal' => '', 'excelFormat' => '[$-en-US]m/d/yy h:mm AM/PM;@', 'fieldType' => 'date'],
		'updatedAt' => ['prettyName' => 'Last Updated At', 'defaultVal' => '', 'excelFormat' => '[$-en-US]m/d/yy h:mm AM/PM;@', 'fieldType' => 'date']
	);

	public function run($pid, $secret, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $addEmailAttachment, $reportDlBaseUrl)
	{
		$excelFieldFormats = array();
		$excelColumnHeader = array();
		$i = 1;
		foreach ($this->excelColumnHeaderFormats as $columnName => $columnSettings) {
			$columnLetter = Coordinate::stringFromColumnIndex($i);
			$excelFieldFormats[$columnLetter] = $columnSettings['excelFormat'];
			array_push($excelColumnHeader, $columnSettings['prettyName']);
			++$i;
		}

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

		$users = array();
		$pager = new FilterPager();
		$pager->pageSize = GetUsersUtil::CYCLE_SIZES;
		$pager->pageIndex = 1;
		$filter = new UserFilter();
		$filter->statusEqual = UserStatus::ACTIVE;
		$filter->isAdminEqual = false;
		$filter->typeEqual = UserType::USER;
		$filter->loginEnabledEqual = true;

		$usersList = $this->getFullListOfKalturaObject($filter, $this->client->getUserService(), 'id', null, true);
		foreach ($usersList as $userId => $user) {
			if (isset($user->registrationInfo) && $user->registrationInfo != null && $user->registrationInfo != '') {
				$userProfile = json_decode($user->registrationInfo);
				$userAttendance = json_decode($user->attendanceInfo);
				foreach ($this->excelColumnHeaderFormats as $columnName => $columnSettings) {
					if (isset($user->{$columnName}) && $columnName != 'status') { //take status from registrationInfo instead of user status
						if (!isset($users[$userId]) || $users[$userId] == null)
							$users[$userId] = array();
						$users[$userId][$columnName] = $user->{$columnName};
					} elseif (isset($userProfile->{$columnName})) {
						if (!isset($users[$userId]) || $users[$userId] == null)
							$users[$userId] = array();
						$fvalue = $userProfile->{$columnName};
						if (is_array($fvalue)) {
							$fvalue = implode(',', $fvalue);
						}
						$users[$userId][$columnName] = $fvalue;
					} elseif (isset($userAttendance->{$columnName})) {
						if (!isset($users[$userId]) || $users[$userId] == null)
							$users[$userId] = array();
						$fvalue = $userAttendance->{$columnName};
						if (is_array($fvalue)) {
							$fvalue = implode(',', $fvalue);
						}
						$users[$userId][$columnName] = $fvalue;
					} else {
						$users[$userId][$columnName] = $columnSettings['defaultVal'];
					}
					if (isset($columnSettings['fieldType']) && $columnSettings['fieldType'] == 'date' && $users[$userId][$columnName] != '')
						$users[$userId][$columnName] = $this->convertTimestamp2Excel($users[$userId][$columnName]);
				}
			} else {
				echo 'skipped user for lack of profile: ' . $userId . PHP_EOL;
			}
		}

		$data = array();
		foreach ($users as $user_id => $user) {
			$row = array();
			foreach ($user as $userprofile_field => $userprofile_value) {
				$row[] = $userprofile_value;
			}
			array_push($data, $row);
		}

		$date = date("MdY-HiT");
		$xslxfile = '/home/ubuntu/export-users-report/user-profiles-' . $pid . '.xlsx';
		$this->writeXLSX($xslxfile, $data, $excelColumnHeader, $excelFieldFormats);
		echo 'Successfully exported data!' . PHP_EOL;
		echo 'File name: ' . $xslxfile . PHP_EOL;
		$filepath = $addEmailAttachment ? $xslxfile : null;
		$this->sendSESmail($pid, $date, $filepath, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $reportDlBaseUrl);
	}
	
	private function sendSESmail ($pid, $date, $filepath, $emailSender, $emailRecipients, $usernameSmtp, $passwordSmtp, $reportDlBaseUrl) {
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
    				echo "Email sent!" , PHP_EOL;
		} catch (phpmailerException $e) {
    				echo "An error occurred. {$e->errorMessage()}", PHP_EOL; //Catch errors from PHPMailer.
		} catch (Exception $e) {
		    		echo "Email not sent. {$mail->ErrorInfo}", PHP_EOL; //Catch errors from Amazon SES.
		}
	}

	private function writeXLSX($filename, $rows, $keys = [], $formats = [])
	{
		// instantiate the class
		$doc = new Spreadsheet();
		Cell::setValueBinder(new AdvancedValueBinder());
		$locale = 'en-US';
		$validLocale = Settings::setLocale($locale);
		$sheet = $doc->getActiveSheet();

		// $keys are for the header row.  If they are supplied we start writing at row 2
		if ($keys) {
			$offset = 2;
		} else {
			$offset = 1;
		}

		// write the rows
		$i = 0;
		foreach ($rows as $row) {
			$doc->getActiveSheet()->fromArray($row, null, 'A' . ($i++ + $offset));
		}

		// write the header row from the $keys
		if ($keys) {
			$doc->setActiveSheetIndex(0);
			$doc->getActiveSheet()->fromArray($keys, null, 'A1');
		}

		// get last row and column for formatting
		$last_column = $doc->getActiveSheet()->getHighestColumn();
		$last_row = $doc->getActiveSheet()->getHighestRow();

		// autosize all columns to content width
		for ($i = 'A'; $i <= $last_column; $i++) {
			$doc->getActiveSheet()->getColumnDimension($i)->setAutoSize(true);
		}

		// if $keys, freeze the header row and make it bold
		if ($keys) {
			$doc->getActiveSheet()->freezePane('A2');
			$doc->getActiveSheet()->getStyle('A1:' . $last_column . '1')->getFont()->setBold(true);
		}

		// format all columns as text
		$doc->getActiveSheet()->getStyle('A2:' . $last_column . $last_row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
		if ($formats) {
			// if there are user supplied formats, set each column format accordingly
			// $formats should be an array with column letter as key and one of the PhpOffice constants as value
			// https://phpoffice.github.io/PhpSpreadsheet/1.2.1/PhpOffice/PhpSpreadsheet/Style/NumberFormat.html
			// EXAMPLE:
			// ['C' => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_00, 'D' => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_00]
			foreach ($formats as $col => $format) {
				$doc->getActiveSheet()->getStyle($col . $offset . ':' . $col . $last_row)->getNumberFormat()->setFormatCode($format);
			}
		}

		// write and save the file
		$writer = new Xlsx($doc);
		$writer->setPreCalculateFormulas(false);
		$writer->save($filename);
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
		}
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
exec("rm -f user-profiles-3*");
foreach (PARENT_PARTNER_IDS as $pid => $secret) {
	$instance = new GetUsersUtil();
	$instance->run($pid, $secret, EMAIL_SENDER, EMAIL_RECIPIENTS, SMTP_USERNAME, SMTP_PASSWORD, SHOULD_SEND_EMAIL_ATTACHMENTS, REPORT_BASE_URL);
	unset($instance);
}
$executionTime->end();
echo $executionTime;