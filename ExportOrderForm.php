<?php

class ExportOrderForm extends CFormModel {
    private $_iiko;
    private $_userId=null;
    private $idOrder;
    private $_orderIikoNumber;
    private $_modelOrder;
    public  $exportErrors=array();
    private $_iikoStatus;

        const STASUS_SENT                = 1;//успешно отправленный в iiko
        const STASUS_NO_CONNECTION       = 2;//Нет связи с Iiko
        const STASUS__IIKO_ERROR         = 3;//не принятый в Iiko
        

    
    public function rules() {
        return array(
           // array('importErrors, importImages', 'safe'),
        );
    }

    public function ExportIikoId($newOrderId) {
        $this->idOrder  = $newOrderId;
        $modelOrder = $this->getModelOrder();
        if($modelOrder == null){
            $this->exportErrors[] = 'Заказ № '.$newOrderId.' в таблице Orders не найден';
            return false;
        }
        //поступивший заказ с онлайн-оплатой, но  с неподтверждённой оплатой
        //посылаем об этом сообщение администратору, но только один раз
        //с неоплаченным статусом в Iiko не посылаем
        if($modelOrder->status ==Orders::STATE_ECARD_NEW && !$modelOrder->isMessage){
                $text = 'Отправлен новый заказ, онлайн оплата ещё не подтверждена';
                $this->sendOrderAdmin($text);
                $modelOrder->isMessage = 1;
                $modelOrder->save();
                return false;
        }        

        //if($modelOrder->status !=Orders::STATE_NEW)return false;//повторно не отправляем
        if($modelOrder->status == Orders::STATE_CONFIRMED  ||
           $modelOrder->status ==Orders::STATE_IIKO_ERROR ||
           $modelOrder->status ==Orders::STATE_CANCELLATIONS ||
           $modelOrder->status ==Orders::STATE_CONFIRMED_SITE ||
           $modelOrder->status ==Orders::STATE_ECARD_NEW 
                )return false;//повторно, или не оплаченные не отправляем

        //отправка в iiko
        if($this->orderIiko()){
            $modelOrder->status = Orders::STATE_CONFIRMED;
            /*
             * если пользователь не определён заранее и не сохранён в таблицу Orders
             * сохраняем в Orders сгенерированный id пользователя
             * и на основании его пытаемся создать нового пользователя
             */
            if(empty($modelOrder->userId)){
              $modelOrder->userId = $this->_userId;  
            }
            $modelOrder->orderIikoNumber = $this->_orderIikoNumber;// номер заказа из iiko
            $modelOrder->save();            
            Users::generateOrderUser($modelOrder->id);

            //отправить email пользователю
            $text = 'Ваш заказ принят!';
            $this->sendOrder($text);
            if(!$modelOrder->isMessage){
                $text = 'Отправлен новый заказ';
                if($modelOrder->paymentsMethod == Orders::ECARD){
                    $text = 'Отправлен новый заказ, онлайн-оплата подтверждена';
                }
                $this->sendOrderAdmin($text);
            }
            $modelOrder->isMessage = 1;
                return true;
        }
        else{
            if($this->_iikoStatus ==self::STASUS__IIKO_ERROR){
                $modelOrder->status = Orders::STATE_IIKO_ERROR;
                $modelOrder->orderIikoNumber = $this->_orderIikoNumber;// номер заказа из iiko
                $modelOrder->save(); 
            }
            elseif($this->_iikoStatus ==self::STASUS_NO_CONNECTION){
                //случаи недоступности сервера, либо долгое ожидание ответа от него
                $modelOrder->status = Orders::STATE_NEW;
                if(!$modelOrder->isMessage){
                    $text = 'Отправлен новый заказ';
                    if($modelOrder->paymentsMethod == Orders::ECARD){
                        $text = 'Отправлен новый заказ, онлайн-оплата подтверждена';
                    }
                    $this->sendOrderAdmin($text);
                }
                $modelOrder->isMessage = 1;
                $modelOrder->save();
                return false;
            }
            
            $text = $this->exportErrors[] = 'Заказ № '.$newOrderId.' не удалось отправить';

            //отправить email пользователю
            $this->sendOrder($text);
            if($modelOrder->paymentsMethod == Orders::ECARD){
                    $text = 'Не удалось отправить заказ, онлайн-оплата подтверждена';
            }             
            //отправить email администратору об ошибке
            $this->sendOrderAdmin($text);
            return false;
        }
        return false;
    }
    
    //переводит статус  неотправленных заказов в ошибочные, старше $timeIntrval  минут.
    private function DeleteOldOrder($timeIntrval){
        $date = new DateTime(date("Y-m-d H:i:s")); 
        $date->sub(new DateInterval("PT".$timeIntrval."M"));
        $dateFrom = $date->format('Y-m-d H:i:s');
        $attributes = array('status'=>Orders::STATE_IIKO_ERROR);
        $condition = "timestamp < '$dateFrom' AND status =:STATE_NEW";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
               'params'    =>array(':STATE_NEW'=>Orders::STATE_NEW)
            ));
        try {
             Orders::model()->updateAll($attributes,$criteria);
             return true;
        } catch (Exception  $e) {
           return false;
        } 
    }
    //переводит статус  неоплаченных заказов в ошибочные, старше $timeIntrval  минут.
    private function DeleteOldOrderEcard($timeIntrval=120){
        $date = new DateTime(date("Y-m-d H:i:s")); 
        $date->sub(new DateInterval("PT".$timeIntrval."M"));
        $dateFrom = $date->format('Y-m-d H:i:s');
        $attributes = array('status'=>Orders::STATE_IIKO_ERROR);
        $condition = "timestamp < '$dateFrom' AND status =:STATE_ECARD_NEW ";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
               'params'    =>array(':STATE_ECARD_NEW '=>Orders::STATE_ECARD_NEW )
            ));
        try {
             Orders::model()->updateAll($attributes,$criteria);
             return true;
        } catch (Exception  $e) {
           return false;
        } 
    }
    public function ExportIikoAll() {
        //проверка на рабочее время
        if(!OrderForm::isTimeSales())return false;
        $timeIntrval=(int)app()->settings->timeIntrval;   
        if(empty($timeIntrval))$timeIntrval = 60;
        $this->DeleteOldOrder($timeIntrval);
        $timeIntrvalPayKeep=(int)app()->settings->timeIntrvalPayKeep;   
        if(empty($timeIntrvalPayKeep))$timeIntrvalPayKeep = 120;
        $this->DeleteOldOrderEcard($timeIntrvalPayKeep);
        $criteria = new CDbCriteria();
        $criteria->addInCondition("status", array(Orders::STATE_NEW, Orders::STATE_ECARD_PAID, Orders::STATE_ECARD_NEW), 'AND');
        $model=Orders::model()->findAll($criteria);
        //VarDumper::dump($model);die();
        if($model==null)return false;
        foreach($model as $item){
              $this->_modelOrder = $item;
              $this->exportIikoId($item->id);
        }
        return true;//$model;
    }
    //отправка заказа
    private function OrderIiko(){
        
        $iiko = $this->getIiko();
        if($iiko->_errorStart){
            $this->_iikoStatus = self::STASUS_NO_CONNECTION;
            $this->setError(array('orderId'=>$this->idOrder,
                                'textError'=>'Нет связи с iiko')
                          );
            return false;   
        }
        /**/
        $modelOrder = $this->getModelOrder();
        /*
         * если пользователь известен - он заранее определяется  и сохраняеться в таблицу заказов, используем его
         * если нет - генерируем нового в переменную класса,
         * в случае удачной отправки используем в дальнейшем этот id для создания нового пользователя
         */
        if(empty($modelOrder->userId)){
           $this->_userId = $order['customer']['id']=Guid::getGUID();//у покупателя должен быть guid, сгенерируем его
        }
        else{
            $order['customer']['id']=$modelOrder->userId;
        }
        //return true;//заглушка для тестирования

        $order['order']['id']=  Guid::getGUID();// у заказа должен быть guid, сгенерируем его
        $order['order']['date'] = $modelOrder->timestamp; //date('Y-m-d H:i:s');//у заказа должна быть дата в данном формате 2014-04-15 12:15:20
        $order['organization']=$iiko->organization;
        $order['deliveryTerminal'] ='f953f6de-4880-9c4e-015e-33d703d86988';
        $order['customer']['name']= $modelOrder->name.' '.$modelOrder->surname;
        $order['customer']['phone']= $modelOrder->phone;
        $order['order']['personsCount']=$modelOrder->countPerson;
        $order['order']['fullSum']=$modelOrder->sumPrice + $modelOrder->priceDelivery;
        /*
        $order['order']['address']['city']='Рязань';//
        $order['order']['address']['street']='Ленина';//адресс всегда один и тот-же, который iiko приимает без ошибок
        $order['order']['address']['home']='16';//
        /**/
        /**/
        $order['order']['address']['city']='Рязань';
        $order['order']['address']['street']=$modelOrder->streetName;
        $order['order']['address']['home']=$modelOrder->addressHouse;
        $order['order']['address']['housing']=$modelOrder->addressHouseBlock;
        $order['order']['address']['apartment']=$modelOrder->addressApartment;
        
        $paymentModel = $modelOrder->paymentModel;
        if($paymentModel != null){
            $order['order']['paymentItems'][0]['sum'] = $modelOrder->sumPrice + $modelOrder->priceDelivery;
            $order['order']['paymentItems'][0]['paymentType'] = json_decode($paymentModel->text);
            $order['order']['paymentItems'][0]['isProcessedExternally'] = false;
            //отметка об оплате для iiko
            if($modelOrder->status == Orders::STATE_ECARD_PAID){
                $order['order']['paymentItems'][0]['isProcessedExternally'] = true;
                $order['order']['paymentItems'][0]['isExternal'] = true;
            }        
        }
        
        /**/
        
        $order['order']['phone']= $modelOrder->phone;
        $order['order']['comment']='Адрес: '.$modelOrder->address.'. Комментарий: '.$modelOrder->userComment ;

        if(!empty($modelOrder->items)){
            foreach($modelOrder->items as $key=>$item){
                $order['order']['items'][$key]['id'] = $item->itemId;
                $order['order']['items'][$key]['name'] = Catalog::getCaption($item->itemId);
                $order['order']['items'][$key]['amount'] = $item->itemCount;
                $order['order']['items'][$key]['sum'] = $item->itemPrice;
                if(!empty($item->groupModifiers)){
                    $modifiers = Catalog::currentInfoGroupModifiers($item->groupModifiers);//$this->getGroupModifiers($item->groupModifiers);
                   $order['order']['items'][$key]['modifiers'] = $modifiers;
                }
            }
        }
        //отправка заказа
        $orderIikoJson=$iiko->doOrder($order);
        $orderIiko=json_decode($orderIikoJson);
        
        //журналировать всё подряд, включить для отладки
        /*
        $this->setError(array('orderId'=>$modelOrder->id,
                    'textError'=>'смотреть всё',
                    'requestJson'=>json_encode($order),
                    'answerJson'=>$orderIikoJson)
              );
         /**/
        //VarDumper::dump(array($orderIiko, $order));die('exportOrderForm_163');
        /*
         * проверяем ответ на ошибки, записываем их в ExportOrderErrors
         */
        if($orderIiko == null){
            $this->_iikoStatus = self::STASUS__IIKO_ERROR;
            $this->setError(array('orderId'=>$modelOrder->id,
                                'textError'=>'Не удалось  создать заказ, iiko вернул пустой результат',
                                'requestJson'=>json_encode($order))
                          );
            return false; 
        }
        if(!property_exists($orderIiko, 'status')&&!property_exists($orderIiko, 'number')){ 
            //отловим здесь другие ошибки, с которыми возможно повторная отправка заказа
            if(property_exists($orderIiko, 'httpStatusCode')){
                if($orderIiko->httpStatusCode == 504){
                    $this->_iikoStatus = self::STASUS_NO_CONNECTION ;
                    return false;
                }
            }
            
            
            
            $this->_iikoStatus = self::STASUS__IIKO_ERROR;
            $this->setError(array('orderId'=>$modelOrder->id,
                                'textError'=>'Не удалось  создать заказ, некорректные данные',
                                'requestJson'=>json_encode($order),
                                'answerJson'=>$orderIikoJson)
                          );
            return false; 
        }
        
        //если проблема критическая, отмечаем заявку как не отправленную, делаем другие действия
        /*
        $problem = '';
        if(isset($orderIiko->problem)){
            if(isset($orderIiko->problem['problem'])){
                $problem = $orderIiko->problem['problem'];
                if($problem == 'text error'){  //возращаемое описание проблемы
                    $this->setError(array('orderId'=>$modelOrder->id,
                                'textError'=>$orderIiko->problem['problem'],
                                'requestJson'=>json_encode($order),
                                'answerJson'=>$orderIikoJson)
                          );
                    return false;
                }
            }
        } 
        /**/
        //если в iiko присвоен номер заказа - считаем его отправленным
        if($orderIiko->number != null){
            $this->_orderIikoNumber = $orderIiko->number;
            $this->_iikoStatus = self::STASUS_SENT;
            return true;
        }
        else{
            $this->_iikoStatus = self::STASUS__IIKO_ERROR;
            $this->setError(array('orderId'=>$modelOrder->id,
                                'textError'=>'Не удалось  создать заказ, не присвоен номер заказа',
                                'requestJson'=>json_encode($order),
                                'answerJson'=>$orderIikoJson)
                          );
            return false;
        }
        return false;
    }
    //Посылает письма, при подтверждении заказа на сайте
    public function sendOrderConfirmedSite($orderId){
        $this->idOrder  = $orderId;
        //отправить email пользователю
            $text = 'Ваш заказ принят!';
            $this->sendOrder($text);
        //отправить email администратору    
            $text = $this->exportErrors[] = 'Заказ № '.$orderId.' подтверждён на сайте';
            $this->sendOrderAdmin($text);     
    }

    //сообщение пользователю
    private function sendOrder($theme){
        $message = $theme. "<br />\r\n";
        $email = $this->getModelOrder()->email;
        if(empty($email))return false;
        $message.=$this->orderDescriptionSend();
        EMailerAdyn::getInstance()->send($email,  'Тема письма', $message);
    }

    private function sendOrderAdmin($theme){
        $message = $theme. "<br />\r\n";
        $message.=$this->orderDescriptionSend();
        //VarDumper::dump($message);
        EMailerAdyn::getInstance()->send(app()->settings->contactEmail,  'Тема письма', $message);
        //EMailerAdyn::getInstance()->send('parshin_am@adyn.ru',  'Тема письма', $message);
        //$this->sendOrderTest($theme);
    }

    private function sendOrderTest($theme){  
        $message = $theme. "<br />\r\n";
        $message.=$this->orderDescriptionSend();
        //VarDumper::dump($message);
        //EMailerAdyn::getInstance()->send(app()->settings->contactEmail,  'Тема письма', $message);
        EMailerAdyn::getInstance()->send('parshin_am@adyn.ru',  'Тема письма', $message);
    }

    
    private function orderDescriptionSend(){
        if(empty($this->getModelOrder())) $message = "Нет данных о заказе <br />\r\n";
        else{
            $modelOrder = $this->getModelOrder();
            $message   = 'Имя: ' . $modelOrder->name. "<br />\r\n";
            if(!empty($modelOrder->surname))$message .= ', '.$modelOrder->surname. "<br />\r\n";
            $message  .= 'Телефон: ' . $modelOrder->phone. "<br />\r\n";
            $message  .= 'E-mail: ' . $modelOrder->email. "<br />\r\n";
            $message .= 'Номер заказа: ' . $modelOrder->orderIikoNumber . "<br />\r\n";
            $message .= 'На сумму: ' . $modelOrder->sumPrice . "<br />\r\n";
            $message .= 'Адрес: '.$modelOrder->address."<br />\r\n";
            if(!empty($modelOrder->countPerson))$message .= 'Количество персон: ' . $modelOrder->countPerson . "<br />\r\n";
            if(!empty($modelOrder->oddMoney))$message .= 'С какой суммы нужна сдача: ' . $modelOrder->oddMoney . "<br />\r\n";
            $message .= 'Доставка: ' . $modelOrder->priceDelivery . "<br />\r\n";
            $message .= 'Метод оплаты: ' . $modelOrder->paymentMethod . "<br />\r\n";
            if(!empty($modelOrder->userComment))$message .= 'Комментарий: ' . $modelOrder->userComment . "<br />\r\n";
            if (count($modelOrder->items)){
                $message .=  "Товары: <br />\r\n";
                foreach($modelOrder->items as $item){
                        $catalog = Catalog::model()->withDeleted()->findByPk($item->itemId);
                        if(empty($catalog)) continue;
                            $message .= $catalog->caption . "  ";
                            if(!empty($item->groupModifiers)){
                                $modifiers = Catalog::currentInfoGroupModifiers($item->groupModifiers, 'arhive');
                                $modifiersText ='(Модификаторы: ';
                                if(!empty($modifiers)){
                                    foreach($modifiers as $one){
                                        $modifiersText.= $one['amount']. ' x '. $one['name'].'; ';
                                    }
                                }
                                $modifiersText.='),  ';
                                $message .=$modifiersText;
                            }
                            $message .= $item->itemCount . " шт. по ";
                            $message .= $item->itemPrice . "руб. Всего ";
                            $message .= $item->sumPrice . "руб. <br />\r\n";
                }
            }

            //$model->paymentMethod
            //сформировать описание
        }
        return $message;
    }  
    //запись ошибок экспорта в таблицу
    private function setError($array){
        $model = new ExportOrderErrors();
        foreach($array as $key=>$item){
            if($model->hasAttribute($key)){
                $model->{$key}=$item;
            }
        }
        $model->save();
    }
    //вернёт модель iiko
    private function getIiko(){
        if($this->_iiko !== null){
            return $this->_iiko;
        }
        else{
            $this->_iiko = new Iiko();
            return $this->_iiko;
        }
        return null;
    }
    //вернёт модель Order если есть по id
    private function getModelOrder(){
        if($this->_modelOrder !== null){
            return $this->_modelOrder;
        }
        else{
            $this->_modelOrder = Orders::model()->findByPk($this->idOrder);
            if($this->_modelOrder ==null) return null;
            if($this->_modelOrder->sumPrice ===0){
               $this->_modelOrder = null;
               return null;
            }
            return $this->_modelOrder;
        }
        return null;
    }

}
