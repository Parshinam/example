<?php

class AnketaController extends ClientControllerBase
{
	public function accessRules()
	{
		return array(
			array('allow', // allow admin user to perform 'admin' and 'delete' actions
				'actions'=>array('index','view','create','update','delete'),
				'roles'=>array('administrator', 'developer', 'director', 'broker', '*'),
			),

		);
	}
    
    

    public function actionIndex($group = 1, $requestKod=0)
    { 
        $this->layout = '//layouts/anketa';
        if(!in_array($group, [1, 2, 3])) $group = 1;
        $questionarymodel = Questionary::model()->findByAttributes(array('requestKod'=>$requestKod));
        if($questionarymodel == null)
                throw new CHttpException(404, 'Страница не найдена');
        if(user()->checkAccess('broker')){ 
            $this->breadcrumbs = array('Заявки'=>array('questionary/index'), $questionarymodel->caption =>$this->createUrl('questionary/view', array('id' => $questionarymodel->id)), 'Заполнить'); 
        }

        if(user()->checkAccess('broker')){
            $this->render('group'.$group, array('requestKod' => $requestKod, 'group'=>$group, 'questionarymodel'=>$questionarymodel));
        }       
        else{
            if($questionarymodel->status == Questionary::STATE_ANKETA_INWORK){
                $this->render('group'.$group, array('requestKod' => $requestKod, 'group'=>$group, 'questionarymodel'=>$questionarymodel));
            }
            else{
                $this->render('check', array('questionarymodel'=>$questionarymodel));
            }
        }        
    }    

    public function actionIsReady($requestKod=0)
    { 
        $questionarymodel = Questionary::model()->findByAttributes(array('requestKod'=>$requestKod));
        if($questionarymodel == null)
                throw new CHttpException(404, 'Страница не найдена');
        $questionarymodel->status  = Questionary::STATE_SEND_BROCKER;
        if($questionarymodel->save()){
            $modelBrocker = $questionarymodel->broker;
            if($modelBrocker !== null){
                $message = new Messages;
                $message->email = $modelBrocker->email;
                $message->theme = 'Анкета отправлена клиентом на рассмотрение';
                $message->message = MessageTemplates::isReady($questionarymodel, $modelBrocker);
                $message->date = date("Y-m-d H:i:s");
                $message->save();                
            }
        }
        $redirect = array('index','requestKod'=>$requestKod);
        if(user()->checkAccess('broker'))
            $redirect = array('/questionary/view','id'=>$questionarymodel->id);
        $this->redirect($redirect);

    } 


    public function actionUpdate()
    { 
        $value = Yii::app()->request->getParam('value', null);
        $requestKod = Yii::app()->request->getParam('requestKod', 0);
        $kod = Yii::app()->request->getParam('kod', null);
        
        $model = AnketaFiledForm::model($kod, $requestKod);
        $model->value = $value;
        //Значение не выбрано для select
        if($model->type == QuestionaryFields::TYPE_RADIO && $value === null)
            $model->value = 0;
        
        //проверка на взаимоисключающие поля
        $dependentFields = $model->getDependentFieldIds();
        $clearFields = false;
        if($dependentFields){
            //удаляем ранее выбраные связанные поля
            $clearFields = $model->modelQuestionary->clearfiledIds($dependentFields, $kod);
            if(empty($clearFields))$clearFields = false;
        }
        if(!$model->validate()){
            echo CActiveForm::validate($model);;
        }
        else{
            if($model->save()){
                //скрыть связанные поля
                $modelNew = AnketaFiledForm::model($kod, $requestKod);
                $hiddenFileds = $modelNew->hiddenFileds;
                echo CJSON::encode(array(
                    'kod' => 1,
                    'clearFields'=>$clearFields,
                    'hiddenFileds'=>(!empty($hiddenFileds)) ? $hiddenFileds : ''
                ));
            }
        }

    }

    public function actionClearfileds()
    { 
        $requestKod = Yii::app()->request->getParam('requestKod', 0);
        $kods = Yii::app()->request->getParam('kods', null);
        $modelQuestionary = Questionary::model()->findByAttributes(array('requestKod' => $requestKod));
        if($modelQuestionary !==null){
            if($modelQuestionary->clearfileds($kods)){
                echo CJSON::encode(array(
                    'kod' => 1,
                ));
            }
        }
    }


    public function loadModel($id)
    {      
 
    }


    
}
