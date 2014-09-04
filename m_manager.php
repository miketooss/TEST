<?php

switch($a) {
	case 'assignBillList':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		print_r(2);
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		if($holiday->assignStatus == HOLIDAY_ASSIGN_STATUS_NOT_ASSIGN)
		{
			$enterprise = $wsUSDClient->call('Enterprise.get', array('id' => $memberInfo['enterpriseId']), '', true);
			$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
			// 排除性别
			$specificPersonIds = array();
			if($holiday_data['gender']) {
				$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
				foreach($persons as $person) {
					if($person->gender == $holiday_data['gender']) {
						$specificPersonIds[] = $person->id;
					}
				}
				$specificPersonCount = count($specificPersonIds);
				$notWelfarePersonCount = $enterprise->employeeCount - $specificPersonCount;
			}
			else {
				$notWelfarePersonCount = 0;
			}
			$specificPersonIdStr = implode(',', $specificPersonIds);

			$filterNotWelfarePersons = array();
			if($holiday->notWelfareJfpDefineIdStr)
			{
				$notWelfarePersons = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $memberInfo['enterpriseId'], 'enterpriseUserdefinedidstr' => $holiday->notWelfareJfpDefineIdStr, 'personIds' => $specificPersonIdStr));
				if(is_array($notWelfarePersons))
				{
					foreach($notWelfarePersons as $notWelfarePerson)
					{
						$filterNotWelfarePersons[] = $notWelfarePerson;
					}
				}
			}
			$notWelfarePersonCount += count($filterNotWelfarePersons);
			$standardPersonCount = $enterprise->employeeCount - $notWelfarePersonCount;
			
			require(getViewPage('manager/assign-point-detail.php'));
		}
		else {
			$process_forward = getProcessUrl($m, 'pointAssignBillList');
		}
		break;
	
	case 'doExportPointAssignBill':
		set_time_limit(0);
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		require_once(OM_PATH.'PointAssignBillMgr.php');
		$pointAssignBillMgr = PointAssignBillMgr::getInstance();
		$_GET['holidayId'] = $memberInfo['holidayId'];
		$pointAssignBillIds = $pointAssignBillMgr->findPointAssignBills($_GET);
		$exportTitleArray = $EXPORT_POINT_ASSIGN_HISTORY;
		if(($tempcount = count($pointAssignBillIds)) > 0) {
			$k = 1;
			$records[0] = $exportTitleArray;
			for($i = 0; $i < $tempcount; $i++) {
				$pointAssignBill = $pointAssignBillMgr->getPointAssignBillById($pointAssignBillIds[$i]->id);
				$records[$k] = array(Util::getCnDateFormat($pointAssignBill->timeDelay, 'Y-m-d'), $pointAssignBill->code, $pointAssignBill->name, $pointAssignBill->holidayName, $POINT_ASSIGN_BILL_STATUS_ARRAY[$pointAssignBill->status]);
				$k++;
				unset($pointAssignBill);
			}
			unset($holidayMgr);
			unset($holiday);
			unset($pointAssignBillIds);
			unset($pointAssignBillMgr);
			ErrorStack::clearErrors();
			require_once(UTIL_PATH.'ExcelUtil.php');
			$reportfilename = ExcelUtil::createDownloadFile($records);
			Util::genHeadDownloadFile($reportfilename);
		}
		else {
			ErrorStack::addError('没有找到符合条件的礼券发放记录');
			$process_forward = getPreviousBrowsePage();
		}
		break;
	
	case 'doAssignPointBill':
		checkRights(APP_MANAGER_VALUE);
		$checkPayPasswordResult = $session->getMember('checkPayPasswordResult');
		$session->deleteMember('checkPayPasswordResult');
		if($checkPayPasswordResult == YES_VALUE) {
			require_once(OM_PATH.'HolidayMgr.php');
			$holidayMgr = HolidayMgr::getInstance();
			$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
			require_once(OM_PATH.'PointAssignBillMgr.php');
			$pointAssignBillMgr = PointAssignBillMgr::getInstance();
			if(is_array($_REQUEST['personId']))//补发积分
			{
				if(count($_REQUEST['personId']) > 0)
					$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId'], 'ids' => implode(',', $_REQUEST['personId'])), '', true);
				else
					$persons = array();
			}
			else {
				$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
				// 排除性别
				$specificPersonIds = $noPersonIdsArray = array();
				if($holiday_data['gender']) {
					$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
					foreach($persons as $person) {
						if($person->gender == $holiday_data['gender']) {
							$specificPersonIds[] = $person->id;
						}
						else {
							$noPersonIdsArray[] = $person->id;
						}
					}
				}
				$specificPersonIdStr = implode(',', $specificPersonIds);

				$personUserDefineds = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $memberInfo['enterpriseId'], 'enterpriseUserdefinedidstr' => $holiday->notWelfareJfpDefineIdStr, 'personIds' => $specificPersonIdStr), '', true);
				if(is_array($personUserDefineds)) {
					foreach($personUserDefineds as $thePerson) {
						$noPersonIdsArray[] = $thePerson->id;
					}
				}

				//全部员工
				$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId'], 'noIds' => implode(',', $noPersonIdsArray)), '', true);
			}
			
			if(count($persons) > 0) {
				$failCount = $successCount = 0;
				$autoRedirect = YES_VALUE;
				$holidayAssignStatus = $_REQUEST['assignType'] == 'immediately' ? HOLIDAY_ASSIGN_STATUS_ASSIGNED : HOLIDAY_ASSIGN_STATUS_WAITING_ASSIGN;
				$pointAssignBillStatus = $holidayAssignStatus == HOLIDAY_ASSIGN_STATUS_ASSIGNED ? POINT_ASSIGN_BILL_STATUS_FINISHED : POINT_ASSIGN_BILL_STATUS_PROCESS;
				if(is_array($persons)) {
					foreach($persons as $person) {
						$id = $pointAssignBillMgr->addPointAssignBill(array('holidayId' => $memberInfo['holidayId'],
																			'enterpriseId' => $memberInfo['enterpriseId'],
																			'personId' => $person->id,
																			'code' => $person->code,
																			'name' => $person->name,
																			'auditorId' => $memberInfo['personId'],
																			'holidayName' => $holiday->title,
																			'status' => $pointAssignBillStatus,
																			'timeDelay' => $pointAssignBillStatus == POINT_ASSIGN_BILL_STATUS_FINISHED ? date('Y-m-d') : $_REQUEST['timeDelay'],
																			));
						if($id) $successCount++;
					}
					$addonMessage = '成功发放'.$successCount.'人';
				}
				if(!is_array($_REQUEST['personId']))//补发不修改节日发放状态
					$holidayMgr->changeHoliday(array('id' => $memberInfo['holidayId'], 'assignStatus' => $holidayAssignStatus));
			}
			else {
				ErrorStack::addError(VALID_PARAMS_ERROR);
			}
		}
		else
		{
			ErrorStack::addError(INVALID_OPERATE);
		}
		$process_forward = getProcessUrl('main', 'result', '&autoRedirect='.$autoRedirect.'&message='.urlencode($holidayAssignStatus == HOLIDAY_ASSIGN_STATUS_WAITING_ASSIGN ? '操作完成！'.$addonMessage.'<br />礼券将于'.$_REQUEST['timeDelay'].'自动发放！' : '操作完成！'.$addonMessage.'<br />员工将立即收到礼券！').'&returnUrl='.urlencode(getProcessUrl($m, 'pointAssignBillList')));
		break;
	
	case 'manualAssignPoint':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		if($holiday->notWelfareJfpDefineIdStr) {
			$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
			// 排除性别
			$specificPersonIds = $excludePersonIds = array();
			if($holiday_data['gender']) {
				$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
				foreach($persons as $person) {
					if($person->gender == $holiday_data['gender']) {
						$specificPersonIds[] = $person->id;
					}
					else {
						$excludePersonIds[] = $person->id;
					}
				}
			}
			$specificPersonIdStr = implode(',', $specificPersonIds);

			$notWelfarePersons = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $memberInfo['enterpriseId'], 'enterpriseUserdefinedidstr' => $holiday->notWelfareJfpDefineIdStr, 'personIds' => $specificPersonIdStr));
			if(is_array($notWelfarePersons)) {
				foreach($notWelfarePersons as $notWelfarePerson) {
					$excludePersonIds[] = $notWelfarePerson->id;
				}
			}
		}

		require(getViewPage('manager/manual-assign-point.php'));
		break;
	
	case 'holidayPersonList':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$pagination = new Pagination2();
		if($_REQUEST['point'] > 0) {
			$personIds = 'null';
		}
		else {
			$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
			// 排除性别
			$specificPersonIds = $noPersonIdsArray = array();
			if($holiday_data['gender']) {
				$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
				foreach($persons as $person) {
					if($person->gender == $holiday_data['gender']) {
						$specificPersonIds[] = $person->id;
					}
					else {
						$noPersonIdsArray[] = $person->id;
					}
				}
			}
			$specificPersonIdStr = implode(',', $specificPersonIds);

			//查看标准福利的员工名单
			$personUserDefineds = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $memberInfo['enterpriseId'], 'enterpriseUserdefinedidstr' => $holiday->notWelfareJfpDefineIdStr, 'personIds' => $specificPersonIdStr), '', true);
			if(is_array($personUserDefineds)) {
				foreach($personUserDefineds as $thePerson) {
					$noPersonIdsArray[] = $thePerson->id;
				}
			}
			$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId'], 'noIds' => implode(',', $noPersonIdsArray)), $pagination, true);
			
		}
		require(getViewPage('manager/person-assign-list.php'));
		break;

	case 'doExportPersonList':
		set_time_limit(0);
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
		// 排除性别
		$specificPersonIds = $noPersonIdsArray = array();
		if($holiday_data['gender']) {
			$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
			foreach($persons as $person) {
				if($person->gender == $holiday_data['gender']) {
					$specificPersonIds[] = $person->id;
				}
				else {
					$noPersonIdsArray[] = $person->id;
				}
			}
		}
		$specificPersonIdStr = implode(',', $specificPersonIds);

		$records = array($PERSON_LIST_ARRAY);
		$personUserDefineds = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $holiday->enterpriseId, 'enterpriseUserdefinedidstr' => $holiday->notWelfareJfpDefineIdStr, 'personIds' => $specificPersonIdStr), '', true);
		if(is_array($personUserDefineds)) {
			foreach($personUserDefineds as $thePerson) {
				$noPersonIdsArray[] = $thePerson->id;
			}
		}
		$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $holiday->enterpriseId, 'noIds' => implode(',', $noPersonIdsArray)), '', true);
		if(is_array($persons)) {
			foreach($persons as $person) {
				$records[] = array($person->code, $person->name, 1);
			}
		}
		require_once(UTIL_PATH.'ExcelUtil.php');
		$reportfilename = ExcelUtil::createDownloadFile($records);
		Util::genHeadDownloadFile($reportfilename);
		break;

	case 'pointAssignBillList':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		try {
			require_once(OM_PATH.'PointAssignBillMgr.php');
			$pointAssignBillMgr = PointAssignBillMgr::getInstance();
			$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
			$pointAssignBillIds = $pointAssignBillMgr->findPointAssignBills(array('enterpriseId'=>$memberInfo['enterpriseId'], 'holidayId'=>$memberInfo['holidayId'], 'statusStr'=>POINT_ASSIGN_BILL_STATUS_PROCESS.','.POINT_ASSIGN_BILL_STATUS_FINISHED.','.POINT_ASSIGN_BILL_STATUS_USED));
			$total_qty = $total_persons = $delay_qty = $unuse_qty = 0;
			if(is_array($pointAssignBillIds) && count($pointAssignBillIds)) {
				$personIds = array();
				foreach($pointAssignBillIds as $pointAssignBillId) {
					$pointAssignBill = $pointAssignBillMgr->getPointAssignBillById($pointAssignBillId->id);
					if($pointAssignBill->status == POINT_ASSIGN_BILL_STATUS_PROCESS) {
						$delay_qty++;
					}
					else {
						$personIds[$pointAssignBill->personId] = $pointAssignBill->personId;
						if($pointAssignBill->status == POINT_ASSIGN_BILL_STATUS_FINISHED) $unuse_qty++;
					}
				}
				$total_qty = count($pointAssignBillIds);
				$total_persons = count($personIds);
			}
			$pagination = new Pagination2();
			$_GET['holidayId'] = $memberInfo['holidayId'];
			$_GET['sortField'] = 'status asc,id desc';
			$pointAssignBillIds = $pointAssignBillMgr->findPointAssignBills($_GET, $pagination);
			require(getViewPage('manager/point-assign-bill-list.php'));
			rememberBrowsePage();
		}
		catch(Exception $e) {
			ErrorStack::addError($e->getMessage());
			$process_forward = WWW_HOST;
		}
		break;
	
	case 'doCancelPointAssignBill':
		checkRights(APP_MANAGER_VALUE);
		require_once(OM_PATH.'PointAssignBillMgr.php');
		$pointAssignBillMgr = PointAssignBillMgr::getInstance();
		$pointAssignBill = $pointAssignBillMgr->getPointAssignBillById($_REQUEST['id']);
		if($pointAssignBill->holidayId == $memberInfo['holidayId'])
		{
			$pointAssignBillMgr->changePointAssignBill(array('id' => $pointAssignBill->id, 'status' => POINT_ASSIGN_BILL_STATUS_CANCELED));
		}
		else
		{
			ErrorStack::addError(INVALID_OPERATE);
		}
		$process_forward = getPreviousBrowsePage();
		break;
	
	case 'doCancelAllPointAssignBill':
		checkRights(APP_MANAGER_VALUE);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		require_once(OM_PATH.'PointAssignBillMgr.php');
		$pointAssignBillMgr = PointAssignBillMgr::getInstance();
		$pointAssignBill = $pointAssignBillMgr->getPointAssignBillById($_GET['id']);
		$pointAssignBillIds = $pointAssignBillMgr->findPointAssignBills(array('enterpriseId'=>$memberInfo['enterpriseId'], 'holidayId'=>$memberInfo['holidayId'], 'status'=>$_GET['status']));
		if(is_array($pointAssignBillIds)) {
			foreach($pointAssignBillIds as $pointAssignBillId) {
				$pointAssignBillMgr->changePointAssignBill(array('id' => $pointAssignBillId->id, 'status' => POINT_ASSIGN_BILL_STATUS_CANCELED));
			}
		}
		// 修改节日发放状态
		if($_GET['status'] == POINT_ASSIGN_BILL_STATUS_PROCESS) {
			$holidayMgr->changeHoliday(array('id' => $memberInfo['holidayId'], 'assignStatus' => HOLIDAY_ASSIGN_STATUS_NOT_ASSIGN));
		}
		$process_forward = getPreviousBrowsePage();
		break;

	case 'ruleSetting':
		checkRights(APP_MANAGER_VALUE);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
		$enterprise = $wsUSDClient->call('Enterprise.get', array('id' => $holiday->enterpriseId), '', true);
		$enterpriseUserDefineds = $wsUSDClient->call('EnterpriseUserDefined.find', array('enterpriseId' => $holiday->enterpriseId));
		// 查找在礼品高级设置中已使用的分类
		require_once(OM_PATH.'GiftGroupMgr.php');
		$giftGroupMgr = GiftGroupMgr::getInstance();
		$giftGroupIds = $giftGroupMgr->findGiftGroups(array('enterpriseId'=>$holiday->enterpriseId, 'holidayId'=>$memberInfo['holidayId']));
		$hiddenDefines = array();
		if(is_array($giftGroupIds) && count($giftGroupIds)) {
			foreach($giftGroupIds as $giftGroupId) {
				$giftGroup = $giftGroupMgr->getGiftGroupById($giftGroupId->id);
				if($giftGroup->memberUserDefined) $hiddenDefines = array_merge($hiddenDefines, explode(',', $giftGroup->memberUserDefined));
			}
		}
		// 排除在礼品高级设置中已使用的分类 否则会造成逻辑冲突
		if(is_array($enterpriseUserDefineds) && count($enterpriseUserDefineds)) {
			foreach($enterpriseUserDefineds as $key => $enterpriseUserDefined) {
				if(in_array($enterpriseUserDefined->id, $hiddenDefines)) unset($enterpriseUserDefineds[$key]);
			}
		}
		// 性别强制过滤
		if($holiday_data['gender']) {
			$tmparr = array('id' => 0);
			switch($holiday_data['gender']) {
				case MALE_VALUE:
					$tmparr['name'] = $GENDER_ARRAY[FEMALE_VALUE].'或未知性别员工';
					break;
				case FEMALE_VALUE:
					$tmparr['name'] = $GENDER_ARRAY[MALE_VALUE].'或未知性别员工';
					break;
			}
			$enterpriseUserDefineds[] = Util::array2object($tmparr);
		}
		require(getViewPage('manager/rule-setting.php'));
		break;
		
	case 'getJfpDefineIdPersonCount':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$holiday_data = $HOLIDAY_DETAIL_ARRAY[$holiday->type];
		// 排除性别
		$specificPersonIds = array();
		if($holiday_data['gender']) {
			$enterprise = $wsUSDClient->call('Enterprise.get', array('id' => $memberInfo['enterpriseId']), '', true);
			$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId']), '', true);
			foreach($persons as $person) {
				if($person->gender == $holiday_data['gender']) {
					$specificPersonIds[] = $person->id;
				}
			}
			$specificPersonCount = count($specificPersonIds);
			$notWelfarePersonCount = $enterprise->employeeCount - $specificPersonCount;
		}
		else {
			$notWelfarePersonCount = 0;
		}
		$specificPersonIdStr = implode(',', $specificPersonIds);

		$filterWelfarePersons = array();
		if($_REQUEST['notWelfareJfpDefineIdStr']) {
			$personUserDefineds = $wsUSDClient->call('PersonUserDefined.findPersonsByUserDefined', array('enterpriseId' => $memberInfo['enterpriseId'], 'enterpriseUserdefinedidstr' => $_REQUEST['notWelfareJfpDefineIdStr'], 'personIds' => $specificPersonIdStr));
			if(!(is_array($personUserDefineds) && count($personUserDefineds) > 0))
				$personUserDefineds = array();
			$notWelfarePersonCount += count($personUserDefineds);
		}
		echo '{';
		echo '"personDefinedCount" : "'.$notWelfarePersonCount.'",';
		echo '"filterWelfarePersonCount" : "'.count($filterWelfarePersons).'",';
		echo '"filterWelfarePersonNames" : "'.implode('，', $filterWelfarePersons).'"';
		echo '}';
		break;
		
	case 'doSaveRuleSetting':
		checkRights(APP_MANAGER_VALUE);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$update = array(
			'id' => $memberInfo['holidayId'],
			'standardCoupon' => $_REQUEST['standardCoupon'],
			'couponPoint' => $_REQUEST['couponPoint'],
			'notifyManagerDate' => $_REQUEST['notifyManagerDate'],
			'notifyEmployee' => $_REQUEST['notifyEmployee'] ? YES_VALUE : NO_VALUE,
			'notifyCouponExpire' => $_REQUEST['notifyCouponExpire'] ? YES_VALUE : NO_VALUE,
			'notWelfareJfpDefineIdStr' => is_array($_REQUEST['enterpriseUserDefined']) ? implode(',', $_REQUEST['enterpriseUserDefined']) : ''
		);
		$holidayMgr->changeHoliday($update);
		$process_forward = getProcessUrl('holiday', 'settings');
		break;

	case 'giftExchangeHistory':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'HolidayMgr.php');
		$holidayMgr = HolidayMgr::getInstance();
		$holiday = $holidayMgr->getHolidayById($memberInfo['holidayId']);
		$pagination = new Pagination2();
		$_REQUEST['holidayId'] = $memberInfo['holidayId'];
		if(isset($_REQUEST['nameSearch']) || isset($_REQUEST['codeSearch']))
		{
			$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId'], 'nameSearch' => $_REQUEST['nameSearch'], 'codeSearch' => $_REQUEST['codeSearch']));
			if(is_array($persons))
			{
				foreach($persons as $person)
				{
					$_REQUEST['personIdStr'] .= ','.$person->id;
				}
			}
			unset($persons);
			$_REQUEST['personIdStr'] = substr($_REQUEST['personIdStr'], 1);
			$_REQUEST['personIdStr'] = $_REQUEST['personIdStr'] == '' ? 'null' : $_REQUEST['personIdStr'];
		}
		require_once(OM_PATH.'MallOrderMgr.php');
		$mallOrderMgr = MallOrderMgr::getInstance();
		$mallOrderIds = $mallOrderMgr->findMallOrder($_REQUEST, $pagination);
		rememberBrowsePage();
		require(getViewPage('manager/exchange-list.php'));
		break;
	
	case 'orderDisplay':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'MallOrderMgr.php');
		$mallOrderMgr = MallOrderMgr::getInstance();
		
		$mallOrder = $mallOrderMgr->getMallOrderById($_GET['id']);
		if($mallOrder->holidayId == $memberInfo['holidayId'])
		{
			if($mallOrder->orderCode) {
				$mallPurchaseOrder = $mallOrderMgr->getMallOrderByCode($mallOrder->orderCode, $mallOrder->interfaceType);
			}
			else {
				$mallPurchaseOrder['status'] = '交易成功';
			}
			require(getViewPage('person/order-display.php'));
		}
		break;

	case 'doExportExchange':
		checkRights(APP_MANAGER_VALUE, true);
		require_once(OM_PATH.'MallOrderMgr.php');
		$mallOrderMgr = MallOrderMgr::getInstance();
		$_REQUEST['holidayId'] = $memberInfo['holidayId'];
		if($_REQUEST['nameSearch'] || $_REQUEST['codeSearch'])
		{
			$persons = $wsUSDClient->call('Person.find', array('enterpriseId' => $memberInfo['enterpriseId'], 'nameSearch' => $_REQUEST['nameSearch'], 'codeSearch' => $_REQUEST['codeSearch']));
			if(is_array($persons))
			{
				foreach($persons as $person)
				{
					$_REQUEST['personIdStr'] .= ','.$person->id;
				}
			}
			unset($persons);
			$_REQUEST['personIdStr'] = substr($_REQUEST['personIdStr'], 1);
			$_REQUEST['personIdStr'] = $_REQUEST['personIdStr'] == '' ? 'null' : $_REQUEST['personIdStr'];
		}
		$mallOrderIds = $mallOrderMgr->findMallOrder($_REQUEST);
		if(($tempcount = count($mallOrderIds)) > 0)
		{
			set_time_limit(0);
			$k = 1;
			$records[0] = $EXPORT_EXCHANGE_HISTORY;
			for($i = 0; $i < $tempcount; $i ++)
			{
				$mallOrder = $mallOrderMgr->getMallOrderById($mallOrderIds[$i]->id);
				$person = $wsUSDClient->call('Person.get', array('id' => $mallOrder->personId), '', true);
				if($mallOrder->orderCode) {
					$mallPurchaseOrder = $mallOrderMgr->getMallOrderByCode($mallOrder->orderCode, $mallOrder->interfaceType);
					$status = $mallPurchaseOrder['status'];
				}
				else {
					$status = '交易成功';
				}
				$records[$k ++] = array($mallOrder->productName, $mallOrder->holidayName, Util::getPointFormat($mallOrder->totalPoint / $mallOrder->quantity),
										$mallOrder->quantity, $status, $person->code, $person->name, Util::getCnDateFormat($mallOrder->timeCreated, 'Y-m-d'),
										);
				unset($person);
				unset($mallOrder);
			}
			unset($mallOrderIds);
			unset($mallOrderMgr);
			ErrorStack::clearErrors();
			require_once(UTIL_PATH.'ExcelUtil.php');
			$reportfilename = ExcelUtil::createDownloadFile($records);
			Util::genHeadDownloadFile($reportfilename);
		}
		else
		{
			ErrorStack::addError('没有找到符合条件的兑换记录');
			$process_forward = WWW_HOST;
		}
		break;

	default:
		require(getWuxingViewPage('error.php'));
		break;
}
?>
