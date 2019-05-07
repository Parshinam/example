<?php

class ImportCatalogForm extends CFormModel {

        public  $importImages=array();
        public  $importModifiers=array();
        public  $importErrors=array();
        public  $basisID;
        public  $pizzaID;
        public  $wokID;
        public  $revision;
        public  $uploadDateRevision;
        public  $oldRevision;
        public  $groups = "";
        public  $products = "";
        
    function __construct() {
        $this->basisID       = app()->settings->idCatalogMain;
        $this->pizzaID       = app()->settings->idConstructorVok;
        $this->wokID         = app()->settings->idConstructorPizza;
        $this->oldRevision   = app()->settings->revision;
    }     
    public function rules() {
        return array(
           // array('importErrors, importImages', 'safe'),
        );
    }
    //Сохраняем все встречающиеся модификаторы в массив
    private function addArrayModifiers($idModifier){
        //проверка на уникальность
        if(!array_search($idModifier, $this->importModifiers)){
            $this->importModifiers[]=$idModifier;
        }
    }
    //перебираем массив модификаторов для сохранения
    private function uploadModifiers(){
        if(empty($this->importModifiers))return false;
        foreach($this->importModifiers as $idModifier){
            $modifier =  $this->searchModifier($idModifier);
            if($modifier){
                $this->addModifier($modifier);
            }
            else{
                $this->importErrors['modifier'][]='модификатор  с ID '.$idModifier.' отсутствует в выгрузке';
                return false;
            }
            //VarDumper::dump($modifier);
        }
       return true;
    }
    //найти  модификатор
    private function searchModifier($idModifier){
        if($this->products==null)return false;
        foreach($this->products AS $product){
            if(!isset($product->id)) continue;
            if($product->id == $idModifier){
                return $product;
            }
        }
        return false;
    }
    //сохранить модификатор
    private function addModifier($modifier){
        
       // VarDumper::dump($modifier);die();
        //сохраняем только с типом Модификатор
        if(isset($modifier->type)){
            if($modifier->type != 'modifier')return false;
        }
        
        $model= $this->getItemIikoId($modifier->id);
        if($model === null){
            $model = new ImportCatalog(); 
            if(isset($modifier->id))             $model->id=$modifier->id;
            if(isset($modifier->order)){
                $model->itemOrder=$modifier->order;
            }
            else{
                $model->itemOrder = $model->getMaxOrd() + 1;
            }           
        }      
        if(isset($modifier->name))               $model->caption=$modifier->name;
        if(isset($modifier->description))        $model->description=$modifier->description;
        if(isset($modifier->order))              $model->itemOrder=$modifier->order;
        if(isset($modifier->price))              $model->price=$modifier->price;
        if(isset($modifier->weight))             $model->weight=$modifier->weight;
       $model->parentId = 4;
       $model->isActive =1;
       $model->type = Catalog::MODIFIER;
       $model->statusImport = 1;
       $model->isDeleted = 0;
       //предварительно сохраняем массив с картинками в одно место
       if(isset($modifier->images)){
           $this->addListImages($modifier);
       }
       if($model->save())return true;
       return false; 
    }
    
    
    //создаём копию таблицы Catalog - import_catalog
    private function copyTableCatalog(){
        $connection=Yii::app()->db;
        $sqlDrop   = 'DROP TABLE IF EXISTS yiiadyncms33_import_catalog';
        $sqlCreate = 'CREATE TABLE yiiadyncms33_import_catalog LIKE yiiadyncms33_catalog';
        $sqlInsert = 'INSERT INTO yiiadyncms33_import_catalog SELECT *FROM yiiadyncms33_catalog';
        try {
             $connection->createCommand($sqlDrop)->execute();
             $connection->createCommand($sqlCreate)->execute();
             $connection->createCommand($sqlInsert)->execute();
             return true;
        } catch (Exception  $e) {
           return false;
        } 
    }
//    //создаём резервную копию таблицы Catalog - backup_catalog  
//     private function backupTableCatalog(){
//        $connection=Yii::app()->db;
//        $sqlDrop   = 'DROP TABLE IF EXISTS yiiadyncms33_backup_catalog';
//        $sqlCreate = 'CREATE TABLE yiiadyncms33_import_catalog LIKE yiiadyncms33_catalog';
//        $sqlInsert = 'INSERT INTO yiiadyncms33_import_catalog SELECT *FROM yiiadyncms33_catalog';
//        try {
//             $connection->createCommand($sqlDrop)->execute();
//             $connection->createCommand($sqlCreate)->execute();
//             $connection->createCommand($sqlInsert)->execute();
//             return true;
//        } catch (Exception  $e) {
//           return false;
//        } 
//    } 
    
    //меняем таблицы Catalog - import_catalog местами;
    private function renameTableCatalog(){
        $connection=Yii::app()->db;
        $sqlDrop   = 'DROP TABLE IF EXISTS yiiadyncms33_catalog';
        $sqlRename = 'RENAME TABLE yiiadyncms33_import_catalog TO yiiadyncms33_catalog';
        $transaction=$connection->beginTransaction();
        try
        {
            $connection->createCommand($sqlDrop)->execute();
            $connection->createCommand($sqlRename)->execute();
            $transaction->commit();
            return true;
        }
        catch(Exception $e) // в случае возникновения ошибки при выполнении одного из запросов выбрасывается исключение
        {
            $transaction->rollback();
            return false;
        } 
    }  
    
    //пометить все записи каталога как предназначенные для удаления
    private function markAfterImport(){
        $attributes = array('statusImport'=>'0');
        $condition = "";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
            ));         
        try {
             ImportCatalog::model()->updateAll($attributes,$criteria);
             return true;
        } catch (Exception  $e) {
           return false;
        }    
    }
    //при импорте записи каталога не создавались/менялись - их удаляем   
    private function deleteBeforeImport(){
        //$condition = "statusImport=0 AND  isDeleted=0";
        $condition = "statusImport=0";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
            )); 
          //Catalog::model()->deleteAll($criteria);
        $models =  ImportCatalog::model()->findAll($criteria);
        foreach($models as $model){
            $model->delete();
        }
    }

    //пометить все картинки как предназначенные для удаления
    private function markAfterImportImages($itemId =0){
        $attributes = array('statusImport'=>'0');
        if($itemId === 0){$condition = "";
        }
        else $condition = "itemId = "."'$itemId'";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
            )); 
        
        $rez =  CatalogImages::model()->updateAll($attributes,$criteria);
    }
    //при импорте картинки не создавались/менялись - их удаляем   
    private function deleteBeforeImportImages($itemId =0){
        if($itemId == 0)$condition = "statusImport=0";
        else $condition = 'statusImport=0 AND itemId = '."'$itemId'";
        $criteria = new CDbCriteria(array(
               'condition' => $condition,
            )); 
        $models =  CatalogImages::model()->findAll($criteria);
        foreach($models as $model){
            $model->delete();
        }
    }
    
    
    
    //записать массив изображений на обработку
    private function addListImages($product){
        if(!empty($product->images)){
            foreach($product->images as $key=>$image){
                if(isset($image->imageId)) $this->importImages[$product->id][$key]['imageId'] = $image->imageId;
                if(isset($image->imageUrl)) $this->importImages[$product->id][$key]['imageUrl'] = $image->imageUrl;
                if(isset($image->uploadDate)) $this->importImages[$product->id][$key]['uploadDate'] = $image->uploadDate;
            }
        }
    }
    //перебор товаров у которых есть изображения
    private function uploadCatalogImages(){
        if(empty($this->importImages))return null;
        foreach($this->importImages as $iikoId=>$images){
            $product = $this->getItemIikoId($iikoId);
            if($product !==null){
                   $this->markAfterImportImages($product->id);//включить перед работой
                   foreach($images as $image){
                       $this->uploadImage($product->id, $image);//$this->importErrors['картинка'][$iikoId][] = $image;
                   }
                  $this->deleteBeforeImportImages($product->id);//включить перед работой
            }
            else{$this->importErrors['product'][$iikoId][] = 'У данного набора картинок отсутствует товар в базе';}
        } 
       // die();
    }

    
    //загрузка одного изображения
    private function uploadImage($itemId, $image){
        if(isset($image['uploadDate'])  &&  isset($image['imageId']) &&  isset($image['imageUrl'])){
        //находим такую-же картинку, с более поздней датой загрузки, и обновляем её, если дата свежая, просто помечаем как актуальную
          $modelImage = $this->getCatalogImage($image['imageId']);
         // $this->importErrors['product'][$image['imageId']][] = 'Изменяем имеющееся изображение';
          if($modelImage !==null){
              if($modelImage->uploadDate < $image['uploadDate'] || !$modelImage->ifImage($modelImage->image)){
                    $modelImage->fileUrl = $image['imageUrl'];
                    $modelImage->uploadDate = $image['uploadDate'];
              }
                   //возможо картинка принадлежит уже другому товару,(у товара сменился ID)
                    $modelImage->itemId = $itemId;
              $modelImage->statusImport = 1;//отмечаем как актуальное
              //VarDumper::dump($modelImage);
              $modelImage->save();
          }
          //создаём новое изображение
          else{
              //$this->importErrors['product'][$image['imageId']][] = 'Создаём новое изображение';
              $modelImage=new CatalogImages();
              $modelImage->id = $image['imageId'];
              $modelImage->fileUrl = $image['imageUrl'];
              $modelImage->itemId = $itemId;
              $modelImage->uploadDate = $image['uploadDate'];
              $modelImage->statusImport = 1;//отмечаем как актуальное
              //$this->importErrors['product'][$image['imageId']][] = $modelImage;
              $modelImage->save();
          }
       }
    }    
    
    

    //вернёт модель если есть по id
    private function getCatalogImage($imageId){
        return CatalogImages::model()->findByPk($imageId);
    }
    
    
    //проверить на наличие по ID_iiko, вернёт модель если есть
    private function getItemIikoId($iikoId){
        return ImportCatalog::model()->resetScope()->findByPk($iikoId);
    }
    //returt (int) parentId
    private function parentIDIiko($parentGroup){
        if($parentGroup == $this->basisID) return 0;
        $model = $this->getItemIikoId($parentGroup);
        if($model !==null)return $model->id;
        return null; 
    } 

    //Добавляем новую / редактируем уже имеющуюся категорию
    private function addImportCategory($category){
        $model= $this->getItemIikoId($category->id);
        if($model === null){
            $model = new ImportCatalog();
            if(isset($category->id))            $model->id=$category->id;
            if(isset($category->order)){
               $model->itemOrder=$category->order;
            }
            else{
               $model->itemOrder = $model->getMaxOrd() + 1;
            }           
        }      
        if(isset($category->name))              $model->caption=$category->name;
        if(isset($category->description))       $model->description=$category->description;
        if(isset($category->order))             $model->itemOrder=$category->order;
        if(isset($category->parentGroup)){
            $model->parentId =$this->parentIDIiko($category->parentGroup);
            if($model->parentId === null)return false;
        }
        
//        if(isset($category->tags)){
//            
//            $this->importErrors['category'][$category->id]['tags'] = $category->tags;
//            $rez=in_array('pizza', $category->tags);
//            $this->importErrors['category'][$category->id]['tags_rez'] = $rez;
//          // VarDumper::dump($category->tags);die();
//        }
        
        
        $model->isDeleted = 0;
        $model->isActive =1;
        $model->statusImport = 1;
        $model->type = Catalog::GROUP;
        if($model->save())return true;
        return false;
    }
    
    private function importCategory($groups){
        $oldGroups=$groups;
        foreach($groups AS $key=>$category){
            if(empty($category->parentGroup)&&empty($category->id))continue;
            $parentId = $this->parentIDIiko($category->parentGroup);
            //такая категория уже есть, и родительская категория уже внесена,  или она нулевого уровня
            if($parentId !==null){
                //добавляем новый, вносим правки в старый
                $this->addImportCategory($category);
                //элемент массива отработан;
                unset($groups[$key]);
                continue;
            }          
        }
        //если остались данные, возможно для них не проинициализирована родительская категория, запускаем цикл ещё раз.
        if(count($oldGroups) > count($groups))$this->importCategory($groups);
        return true;
    }

    //сохраняем груповые модификаторы
    private function AddGroupModifiers($modifiers){
        if(empty($modifiers)) return false;
        foreach($modifiers as $modifier){
            //VarDumper::dump($modifier);
            if(isset($modifier->modifierId)){
               $groupModifier =  $this->searchGroupModifier($modifier->modifierId);
               if($groupModifier)$this->addGroupModifier($groupModifier);
               //сохраняем в массив все встречающиеся модификаторы
               if(!empty($modifier->childModifiers)){
                   $this->addModifiers($modifier->childModifiers);
//                   foreach($modifier->childModifiers as $childModifier){
//                       if(isset($childModifier->modifierId)) $this->addArrayModifiers($childModifier->modifierId);
//                   }
               }
            }
        }
    }
    //сохраняем модификаторы
    private function AddModifiers($modifiers){
        if(empty($modifiers)) return false;
           foreach($modifiers as $modifier){
               if(isset($modifier->modifierId)) $this->addArrayModifiers($modifier->modifierId);
           }
    } 
    
    //найти груповой модификатор
    private function searchGroupModifier($idModifier){
        //return $idModifier;
        if($this->groups==null)return false;
        foreach($this->groups AS $group){
            if(!isset($group->id)) continue;
            if($group->id == $idModifier){
                return $group;
                break;
            }
        }
        return false;
    }
    //сохранить груповой модификатор
    private function addGroupModifier($groupModifier){
        $model= $this->getItemIikoId($groupModifier->id);
        if($model === null){
            $model = new ImportCatalog(); 
            if(isset($groupModifier->id))             $model->id=$groupModifier->id;
            if(isset($groupModifier->order)){
                $model->itemOrder=$groupModifier->order;
            }
            else{
                $model->itemOrder = $model->getMaxOrd() + 1;
            }           
        }      
        if(isset($groupModifier->name))               $model->caption=$groupModifier->name;
        if(isset($groupModifier->description))        $model->description=$groupModifier->description;
        if(isset($groupModifier->order))              $model->itemOrder=$groupModifier->order;
       $model->parentId = 3;
       $model->isActive =1;
       $model->type = Catalog::GROUPMODIFIER;
       $model->isDeleted = 0;
       $model->statusImport = 1;
       //предварительно сохраняем массив с картинками в одно место
       if(isset($groupModifier->images)){
           $this->addListImages($groupModifier);
       }
       if($model->save())return true;
       return false; 
    }





    private function importProducts(){
        $products = $this->products;
        foreach($products AS $key=>$product){
//            //конструкторы для пиццы и вок сохраняем отдельно
//            if(($product->id ==$this->pizzaID || $product->id ==$this->wokID)){
//                $this->addConctructor($product);
//            }
            if(empty($product->parentGroup)&&empty($product->id))continue;
            //$productModel = $this->getItemIikoId($product->id);
            $parentModel = $this->getItemIikoId($product->parentGroup);
            //добавляем новый, вносим правки в старый
            if($parentModel !==null){
                //добавляем новый, вносим правки в старый
                $this->addImportProduct($product);
                continue;
            }
        }
        return true;
    }   
    //Добавляем новый / редактируем старый продукт
    private function addImportProduct($product){
        $model= $this->getItemIikoId($product->id);
        if($model === null){
            $model = new ImportCatalog(); 
            if(isset($product->id))             $model->id=$product->id;
            if(isset($product->order)){
                $model->itemOrder=$product->order;
            }
            else{
                $model->itemOrder = $model->getMaxOrd() + 1;
            }           
        }      
        if(isset($product->name))               $model->caption=$product->name;
        if(isset($product->description))        $model->description=$product->description;
        if(isset($product->price))              $model->price=$product->price;
        if(isset($product->weight))             $model->weight=$product->weight;
        if(isset($product->order))              $model->itemOrder=$product->order;
        if(isset($product->seoDescription))     $model->seoDescription=$product->seoDescription;
        if(isset($product->seoKeyword))         $model->keywords=$product->seoKeyword;
        //if(isset($product->seoText))            $model->seoText=$product->seoText; //поле ещё не создано
        if(isset($product->seoTitle))           $model->seo_title=$product->seoTitle;
        //if(isset($category->icon))              $model->itemOrder=$product->additionalInfo;
        if(isset($product->parentGroup)){
            $parentModel = $this->getItemIikoId($product->parentGroup);
            if($parentModel === null)return false;
            $model->parentId = $parentModel->id;
       }
        if(isset($product->tags)){
            if(in_array('best', $product->tags))$model->isBest=1;
            if(in_array('other', $product->tags))$model->isOther=1;
        }
        //групповые модификаторы
        if(isset($product->groupModifiers)){
            if(!empty($product->groupModifiers)){
            $newGroupModifiers  =  serialize($product->groupModifiers);
            if($model->groupModifiers != $newGroupModifiers){
               $model->groupModifiers = $newGroupModifiers;
               $model->timestampGroupModifiers = date("Y-m-d H:i:s");
            }   
            $this->AddGroupModifiers($product->groupModifiers);                 
            }
            else{
                $model->groupModifiers ='';
            }
        }
        //массив одиночных модификаторов
        if(isset($product->modifiers)){
            if(!empty($product->modifiers)){
            $model->modifiers = serialize($product->modifiers);
            $this->AddGroupModifiers($product->modifiers);                 
            }
            else{
                $model->modifiers ='';
            }
        }       
        
        
       $model->isActive =1;
       $model->type = Catalog::PRODUCT;
       $model->scenario = 'item';
       $model->statusImport = 1;
       $model->isDeleted = 0;
       //предварительно сохраняем массив с картинками в одно место
       if(isset($product->images)){
           $this->addListImages($product);
       }
       if($model->save())return true;
       return false; 
    }
        //конструкторы
//    private function addConctructor($product){
//        $model= $this->getItemIikoId($product->id);
//        if($model === null){
//           $model = new ImportCatalog();
//           if(isset($product->id))                $model->id=$product->id; 
//        }
//        if(isset($product->name))              $model->caption=$product->name;
//        if(isset($product->description))       $model->description=$product->description;
//        //if(isset($product->order))             $model->itemOrder=$product->order;
//        if(isset($product->price))             $model->price=$product->price;
//        if(isset($product->weight))            $model->weight=$product->weight;
//        //if(isset($product->parentGroup))       $model->parentId = $product->parentGroup;
//        
//        
//        
//        if(isset($product->groupModifiers)){
//            $model->groupModifiers = serialize($product->groupModifiers);
//            $this->AddGroupModifiers($product->groupModifiers);    
//        }
//        //$model->parentId = 2;
//        if(isset($product->parentGroup)){
//            $parentModel = $this->getItemIikoId($product->parentGroup);
//            if($parentModel === null)return false;
//            $model->parentId = $parentModel->id;
//        }
//        $model->isActive =1;
//        $model->type = Catalog::PRODUCT;
//        $model->scenario = 'item';
//        $model->statusImport = 1;
//        $model->save();
//    }
    
    
    //проверка версии ревизии, вернёт true если данные новые
    private function isNewRevision($revision){
        $oldRevision = (int)$this->oldRevision;
        //если отсутствует номер ревизии, возможно данные загружаються впервые
        if(empty($oldRevision))return true;
        if($revision >$oldRevision)return true;
        return false;
    }
    //Обновляем номер и дату ревизии
    private function setNewRevision(){
        if(empty($this->revision)) return false;
        try {
             $model = Settings::model()->findByAttributes(array('name'=>'revision'));
             $model->value = $this->revision;
             $model->save();
             
             $model = Settings::model()->findByAttributes(array('name'=>'uploadDate'));
             $model->value = $this->uploadDateRevision;
             $model->save();
             return true;
        } catch (Exception  $e) {
           return false;
        } 
    }
    
    public function importNomenclature($json) {
        $dannie = json_decode($json);
        //проверка версии
        if(isset($dannie->revision)){
            $this->revision = $dannie->revision;
            $this->uploadDateRevision = $dannie->uploadDate;
            //грузить только новые данные
            if(!$this->isNewRevision($dannie->revision)){$this->importErrors['all'][] = 'Нет новых данных';return false;}
        }
        //VarDumper::dump($dannie);die();
        if($dannie === null ){$this->importErrors['all'][] = 'Данные не загруженны, или имеют неправильный формат';return false;}
        //подготавливаем БД для импорта
        //копируем таблицу Catalog  в Import_catalog
        if(!$this->copyTableCatalog()){{$this->importErrors['all'][] = 'Не удалось создать временную таблицу';return false;}}        
        //пометить для удаления все записи
        if(!$this->markAfterImport()){{$this->importErrors['all'][] = 'Не удалось пометить записи для последущего удаления';return false;}}   
        
        //обновляем / загружаем  структуру категорий
        if(empty($dannie->groups)){$this->importErrors['all'][] = 'Во входных данных отсутствует раздел с категориями';return false;};
        $this->groups = $dannie->groups;
           if(!$this->importCategory($dannie->groups)){$this->importErrors['all'][] = 'Не удалось загрузить категории';return false;};
        
        //обновляем / загружаем  товары
        if(empty($dannie->products)){$this->importErrors['all'][] = 'Во входных данных отсутствует раздел с товарами';return false;};
        $this->products = $dannie->products;
            if(!$this->importProducts()){$this->importErrors['all'][] = 'Не удалось загрузить товары';return false;};
        
        //обновляем / загружаем  модификаторы
        if(!empty($this->importModifiers)){
            if(!$this->uploadModifiers()){$this->importErrors['all'][] = 'Не удалось загрузить модификаторы';return false;};
        }
        
        //удалить все помеченные и не изменёные загрузкой
        $this->deleteBeforeImport();
        //загружаем каринки
        if(!empty($this->importImages)){
            $this->uploadCatalogImages();
        }
        //Меняем местами таблицу Catalog
        $this->renameTableCatalog(); 
        //удаляем лишние каринки
        CatalogImages::clearGarbage();
        //сохраняем новый код ревизии
        $this->setNewRevision();

       // $result = $dannie;
        $result = true;
        return $result;
    }
    
    


    public function attributeLabels() {
            }

}
