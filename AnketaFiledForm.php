<?php


class AnketaFiledForm extends CFormModel
{
    public $modelField;//QuestionaryFields model
    public $modelQuestionary;//QuestionaryFields model
    public $value = null;
    public $hiddenFileds = null;
    public $listData;
    public $errors;
    public $kod;
    public $type;
    public $label;
    public $requestKod;
    public $modelValue;
    public $validates = array();
    public $isRequired = false;

    public function init()
    { 
        if($this->kod){
            $this->modelField = QuestionaryFields::model()->findByAttributes(array('kod'=>$this->kod));
            if ($this->modelField === null) return false;
            $this->type = $this->modelField->type;
            $this->label = $this->modelField->label;
            $this->listData = $this->modelField->listData();
 
            if($this->modelQuestionary === null){
                if($this->requestKod){
                    $this->modelQuestionary = Questionary::model()->findByAttributes(array('requestKod'=>$this->requestKod));
                }                
            }
            if($this->modelQuestionary !== null){
                if($this->modelQuestionary){
                    $this->modelValue = $this->modelQuestionary->getModelValue($this->modelField->id);
                    if(!$this->modelValue->isNewRecord) $this->value = $this->modelValue->value;
                    //если поле заполнено, возможно стоит скрывать другие поля, согласно списка
                    if(!empty($this->value)){
                        $this->hiddenFileds = $this->getHiddenFiledsString();
                    }
                } 
            }
            $this->createValidates();
        } 
        else {
            return false;
        }
        
    }
    
    public function getHiddenFiledsString()
    {
        if($this->type == QuestionaryFields::TYPE_VALUE || $this->type == QuestionaryFields::TYPE_RADIO ){
            if(isset($this->modelValue->fieldValues)){
                return $this->modelValue->fieldValues->hiddenFileds;
            } 
        }
        else{
            return $this->modelField->hiddenFileds;
        }
    }
    
    
    public function load($kod, $requestKod = 0)
    {
        $this->kod = $kod;
        $model->requestKod = $requestKod;
        $this->init();
    }

    public function rules()
    {
        return $this->validates;
    }

    public function attributeLabels()
    {
        return array(
            'value'              => $this->label,
        );
    }

    public function save()
    {
        if($this->validate())
        {
            $modelSaved = $this->saveModel();
            return $modelSaved;
        }
        return false;
    }

    public function saveModel()
    {
        if($this->type == QuestionaryFields::TYPE_RADIO){
           //значение 'Не выбрано' - удаляем все значения селекта в ответах
           if($this->value == 0 || $this->value == ''){
              $this->modelValue->delete();
               return true;
           }
           if(!array_key_exists($this->value, $this->listData)){
               return false;
           }
            $this->modelValue->value = $this->value;
        }

        else{
            $this->modelValue->value = $this->value;
        }    
        return $this->modelValue->save();
    }
    
    public function getDependentFieldIds()
    {
        $groupId =  app()->db->createCommand()
            ->select('groupId')
            ->from('{{group_filed_or}}')
            ->where('fieldId = '.$this->modelField->id)
            ->queryScalar();
        if(!$groupId)return false;
        return CHtml::listData(GroupFiledOr::model()->findAllByAttributes(array('groupId'=>$groupId)),'fieldId', 'fieldId');
    }
    
    public function beforeValidate() {
        if($this->type == QuestionaryFields::TYPE_MANEY){
            $this->value = str_replace(['+',' '], '', $this->value);
        }
        if($this->type == QuestionaryFields::TYPE_PHONE_MOBAIL){
            $this->value = PhoneFormat::phoneToMobailNumber($this->value);
        }
        return parent::beforeValidate();
    }

    public static function model($kod,  $requestKod = 0, $questionarymodel = null)
    {
        $model = new self;
        $model->kod = $kod;
        $model->requestKod = $requestKod;
        $model->modelQuestionary = $questionarymodel;
        $model->init();
        return $model;

    }
    private function createValidates(){
        $models = $this->modelField->validates;
        if($models === null)return false;
        foreach($models as $model){
            if($model->type == QuestionaryFieldsValidation::TYPE_REQUIRED){
                $this->validates[] = array('value', 'required');
                $this->isRequired = true;
            }
            elseif($model->type == QuestionaryFieldsValidation::TYPE_INTEGERONLY){
                $arr = array('value', 'numerical', 'integerOnly' => true);
                if(!empty($model->min))$arr['min']=$model->min;
                if(!empty($model->max))$arr['max']=$model->max;
                $this->validates[] = $arr;
            }
            elseif($model->type == QuestionaryFieldsValidation::TYPE_NUMERICAL){
                $arr = array('value', 'numerical');
                if(!empty($model->min))$arr['min']=$model->min;
                if(!empty($model->max))$arr['max']=$model->max;
                $this->validates[] = $arr;
            }
            elseif($model->type == QuestionaryFieldsValidation::TYPE_STRING){
                $arr = array('value', 'length');
                //групповое ограничение
                if(!empty($model->commonMax)){
                    //длина уже заполненых связанных полей
                    $length = $this->modelValue->getCommonStringLength();
                    $rest = $model->commonMax - $length;
                    if($model->max < $rest)
                        $arr['max']=$model->max;
                    else 
                        $arr['max']=$rest;  
                    if(!empty($model->min))$arr['min']=$model->min;
                    $arr['message']= 'В группе полей осталось свободно '.$rest.' символа';
                }
                else{
                    if(!empty($model->min))$arr['min']=$model->min;
                    if(!empty($model->max))$arr['max']=$model->max;                    
                }
                $this->validates[] = $arr;
            }
            elseif($model->type == QuestionaryFieldsValidation::TYPE_NUMBER){
                $arr = array('value', 'isNumber');
                if(!empty($model->min))$arr['min']=$model->min;
                else $arr['min']=0;
                if(!empty($model->max))$arr['max']=$model->max;
                else $arr['max']=255;
                if(!empty($model->separator))$arr['separator']=$model->separator;
                else $arr['separator']='';
                $this->validates[] = $arr;
            }

        }

    }
  
    
    public function isNumber($attribute, $params)
    {
        $min = $params['min'];
        $max = $params['max'];
        $separator = $params['separator'];
        $value = $this->value;
        if($separator != ''){
            $separatorArray = explode(',', str_replace(' ', '', $separator));
            if(!is_array($separatorArray))$separatorArray = $separator;
            $value = str_replace($separatorArray, '', $value);
        }

        $reg = '/^[0-9]{'.$min.','.$max.'}$/';
  
        if (!preg_match($reg, $value)) {
            if($min !=0 & $max !=255){
                if($min == $max)
                    $textError ="поле должно состоять из $min цифр";   
                else 
                    $textError = "поле должно состоять из $min - $max цифр"; 
            }         
            elseif($min ==0 & $max !=255)
                    $textError = "поле не должно превышать $max цифр";
  
            elseif($min !=0 & $max ==255)
                     $textError = "поле должно быть не менее $min цифр"; 
            
            if($separator != '')$textError.= " и разделителя $separator"; 
            
            $this->addError($attribute, $textError);       
        }
    }
    
    
}  
