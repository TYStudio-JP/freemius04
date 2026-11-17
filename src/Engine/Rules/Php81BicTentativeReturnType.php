<?php
/**
 * Methods that override an internal method with a tentative return type and
 * declare no return type at all (tranche 8.1-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: signature.tentative_return_type_missing. Deprecated in 8.1.
 *
 *     class Bag implements Countable {
 *         public function count() { ... }      // no ": int"
 *     }
 *
 * PHP 8.1 gave most non-final internal methods a tentative return type. An
 * override without a compatible return type raises E_DEPRECATED when the
 * class is declared, unless the method carries #[\ReturnTypeWillChange].
 *
 * Reported only when all of this is visible in the file:
 *
 * - the class (named or anonymous) or interface names the internal class or
 *   interface itself in "extends" or "implements";
 * - the method is declared in its body under the internal method's name;
 * - the method has no return type at all, and no ReturnTypeWillChange
 *   attribute (any spelling of the name, so that nothing is reported that
 *   might be silenced).
 *
 * A declared but incompatible return type, a method that comes from a trait,
 * and a class that reaches the internal type through a user class or
 * interface are not reported: the file alone does not tell.
 *
 * The fix puts #[\ReturnTypeWillChange] on a line of its own above the
 * method. PHP 7.4 reads that line as a comment, so the fixed file runs the
 * same on every version.
 *
 * METHODS lists, per internal class or interface, the methods that have a
 * tentative return type on every runtime from 8.1 to 8.5 (php-wasm, with
 * ReflectionMethod::hasTentativeReturnType()), inherited ones included, final
 * classes and final or private methods left out.
 */
final class Php81BicTentativeReturnType implements Rule {

	/**
	 * Asked of Context::function_is_guarded() to learn about a version check
	 * only. Not a valid function name, so no function_exists() matches it.
	 */
	const VERSION_GUARD = '8.1-bic: version check only';

	/**
	 * Internal class or interface, lower case => its methods with a tentative
	 * return type, lower case, separated by spaces.
	 */
	const METHODS = array(
		'appenditerator'                         => 'append current getarrayiterator getinneriterator getiteratorindex key next rewind valid',
		'argumentcounterror'                     => '__wakeup',
		'arithmeticerror'                        => '__wakeup',
		'arrayaccess'                            => 'offsetexists offsetget offsetset offsetunset',
		'arrayiterator'                          => '__debuginfo __serialize __unserialize append asort count current getarraycopy getflags key ksort natcasesort natsort next offsetexists offsetget offsetset offsetunset rewind seek serialize setflags uasort uksort unserialize valid',
		'arrayobject'                            => '__debuginfo __serialize __unserialize append asort count exchangearray getarraycopy getflags getiterator getiteratorclass ksort natcasesort natsort offsetexists offsetget offsetset offsetunset serialize setflags setiteratorclass uasort uksort unserialize',
		'assertionerror'                         => '__wakeup',
		'badfunctioncallexception'               => '__wakeup',
		'badmethodcallexception'                 => '__wakeup',
		'cachingiterator'                        => 'count current getcache getflags getinneriterator hasnext key next offsetexists offsetget offsetset offsetunset rewind setflags valid',
		'callbackfilteriterator'                 => 'accept current getinneriterator key next rewind valid',
		'closedgeneratorexception'               => '__wakeup',
		'compileerror'                           => '__wakeup',
		'countable'                              => 'count',
		'curlfile'                               => 'getfilename getmimetype getpostfilename setmimetype setpostfilename',
		'dateinterval'                           => '__set_state __wakeup createfromdatestring format',
		'dateperiod'                             => '__set_state __wakeup getdateinterval getenddate getrecurrences getstartdate',
		'datetime'                               => '__set_state __wakeup add createfromformat createfromimmutable diff format getlasterrors getoffset gettimestamp gettimezone modify setdate setisodate settime settimestamp settimezone sub',
		'datetimeimmutable'                      => '__set_state __wakeup add createfromformat createfrommutable diff format getlasterrors getoffset gettimestamp gettimezone modify setdate setisodate settime settimestamp settimezone sub',
		'datetimeinterface'                      => '__wakeup diff format getoffset gettimestamp gettimezone',
		'datetimezone'                           => '__set_state __wakeup getlocation getname getoffset gettransitions listabbreviations listidentifiers',
		'directoryiterator'                      => '__debuginfo current getatime getbasename getctime getextension getfileinfo getfilename getgroup getinode getlinktarget getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype isdir isdot isexecutable isfile islink isreadable iswritable key next openfile rewind seek setfileclass setinfoclass valid',
		'divisionbyzeroerror'                    => '__wakeup',
		'domainexception'                        => '__wakeup',
		'domattr'                                => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace isid issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domcdatasection'                        => 'appenddata c14n c14nfile deletedata getlineno getnodepath hasattributes haschildnodes insertdata isdefaultnamespace iselementcontentwhitespace issamenode issupported iswhitespaceinelementcontent lookupnamespaceuri lookupprefix normalize replacedata',
		'domcharacterdata'                       => 'appenddata c14n c14nfile deletedata getlineno getnodepath hasattributes haschildnodes insertdata isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize replacedata',
		'domcomment'                             => 'appenddata c14n c14nfile deletedata getlineno getnodepath hasattributes haschildnodes insertdata isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize replacedata',
		'domdocument'                            => 'c14n c14nfile createcomment createdocumentfragment createtextnode getelementbyid getelementsbytagname getelementsbytagnamens getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize normalizedocument registernodeclass relaxngvalidate relaxngvalidatesource save savehtml savehtmlfile savexml schemavalidate schemavalidatesource validate xinclude',
		'domdocumentfragment'                    => 'appendxml c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domdocumenttype'                        => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domelement'                             => 'c14n c14nfile getattribute getattributens getelementsbytagname getelementsbytagnamens getlineno getnodepath hasattribute hasattributens hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize removeattribute removeattributens setattributens setidattribute setidattributenode setidattributens',
		'domentity'                              => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domentityreference'                     => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domimplementation'                      => 'hasfeature',
		'domnamednodemap'                        => 'count getnameditem getnameditemns item',
		'domnode'                                => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domnodelist'                            => 'count',
		'domnotation'                            => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domprocessinginstruction'               => 'c14n c14nfile getlineno getnodepath hasattributes haschildnodes isdefaultnamespace issamenode issupported lookupnamespaceuri lookupprefix normalize',
		'domtext'                                => 'appenddata c14n c14nfile deletedata getlineno getnodepath hasattributes haschildnodes insertdata isdefaultnamespace iselementcontentwhitespace issamenode issupported iswhitespaceinelementcontent lookupnamespaceuri lookupprefix normalize replacedata',
		'domxpath'                               => 'evaluate query registernamespace registerphpfunctions',
		'emptyiterator'                          => 'current key next rewind valid',
		'error'                                  => '__wakeup',
		'errorexception'                         => '__wakeup',
		'exception'                              => '__wakeup',
		'filesystemiterator'                     => '__debuginfo current getatime getbasename getctime getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype isdir isdot isexecutable isfile islink isreadable iswritable key next openfile rewind seek setfileclass setflags setinfoclass valid',
		'filteriterator'                         => 'accept current getinneriterator key next rewind valid',
		'finfo'                                  => 'buffer file',
		'globiterator'                           => '__debuginfo count current getatime getbasename getctime getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype isdir isdot isexecutable isfile islink isreadable iswritable key next openfile rewind seek setfileclass setflags setinfoclass valid',
		'imagickdrawexception'                   => '__wakeup',
		'imagickexception'                       => '__wakeup',
		'imagickkernelexception'                 => '__wakeup',
		'imagickpixelexception'                  => '__wakeup',
		'imagickpixeliteratorexception'          => '__wakeup',
		'infiniteiterator'                       => 'current getinneriterator key next rewind valid',
		'invalidargumentexception'               => '__wakeup',
		'iterator'                               => 'current key next rewind valid',
		'iteratoraggregate'                      => 'getiterator',
		'iteratoriterator'                       => 'current getinneriterator key next rewind valid',
		'jsonexception'                          => '__wakeup',
		'jsonserializable'                       => 'jsonserialize',
		'lengthexception'                        => '__wakeup',
		'limititerator'                          => 'current getinneriterator getposition key next rewind seek valid',
		'logicexception'                         => '__wakeup',
		'multipleiterator'                       => '__debuginfo attachiterator containsiterator countiterators current detachiterator getflags key next rewind setflags valid',
		'mysqli'                                 => 'autocommit begin_transaction change_user character_set_name commit connect dump_debug_info escape_string get_charset get_client_info get_connection_stats get_server_info get_warnings kill more_results multi_query next_result options ping poll prepare query real_connect real_escape_string real_query reap_async_query refresh release_savepoint rollback savepoint select_db set_charset set_opt stat stmt_init store_result thread_safe use_result',
		'mysqli_result'                          => 'close data_seek fetch_all fetch_array fetch_assoc fetch_field fetch_field_direct fetch_fields fetch_object fetch_row field_seek free free_result',
		'mysqli_stmt'                            => 'attr_get attr_set bind_param bind_result data_seek execute fetch free_result get_result get_warnings more_results next_result num_rows prepare reset result_metadata send_long_data store_result',
		'norewinditerator'                       => 'current getinneriterator key next rewind valid',
		'outeriterator'                          => 'current getinneriterator key next rewind valid',
		'outofboundsexception'                   => '__wakeup',
		'outofrangeexception'                    => '__wakeup',
		'overflowexception'                      => '__wakeup',
		'parentiterator'                         => 'accept current getchildren getinneriterator haschildren key next rewind valid',
		'parseerror'                             => '__wakeup',
		'pdo'                                    => 'begintransaction commit errorcode errorinfo exec getattribute getavailabledrivers intransaction lastinsertid prepare query quote rollback setattribute',
		'pdoexception'                           => '__wakeup',
		'pdostatement'                           => 'bindcolumn bindparam bindvalue closecursor columncount debugdumpparams errorcode errorinfo execute fetch fetchall fetchcolumn fetchobject getattribute getcolumnmeta nextrowset rowcount setattribute',
		'phar'                                   => '__debuginfo addemptydir addfile addfromstring buildfromdirectory buildfromiterator compress compressfiles converttodata converttoexecutable count current decompress extractto getalias getatime getbasename getchildren getctime getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmetadata getmodified getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsignature getsize getstub getsubpath getsubpathname gettype getversion haschildren hasmetadata isbuffering iscompressed isdir isdot isexecutable isfile isfileformat islink isreadable iswritable key next offsetexists offsetget offsetset offsetunset openfile rewind seek setalias setdefaultstub setfileclass setflags setinfoclass setmetadata setsignaturealgorithm startbuffering stopbuffering valid',
		'phardata'                               => '__debuginfo addemptydir addfile addfromstring buildfromdirectory buildfromiterator compress compressfiles converttodata converttoexecutable count current decompress extractto getalias getatime getbasename getchildren getctime getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmetadata getmodified getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsignature getsize getstub getsubpath getsubpathname gettype getversion haschildren hasmetadata isbuffering iscompressed isdir isdot isexecutable isfile isfileformat islink isreadable iswritable key next offsetexists offsetget offsetset offsetunset openfile rewind seek setalias setdefaultstub setfileclass setflags setinfoclass setmetadata setsignaturealgorithm startbuffering stopbuffering valid',
		'pharexception'                          => '__wakeup',
		'pharfileinfo'                           => '__debuginfo chmod getatime getbasename getcompressedsize getcontent getcrc32 getctime getextension getfileinfo getfilename getgroup getinode getlinktarget getmetadata getmtime getowner getpath getpathinfo getpathname getperms getpharflags getrealpath getsize gettype hasmetadata iscompressed iscrcchecked isdir isexecutable isfile islink isreadable iswritable openfile setfileclass setinfoclass setmetadata',
		'php_user_filter'                        => 'filter onclose oncreate',
		'rangeexception'                         => '__wakeup',
		'recursivearrayiterator'                 => '__debuginfo __serialize __unserialize append asort count current getarraycopy getchildren getflags haschildren key ksort natcasesort natsort next offsetexists offsetget offsetset offsetunset rewind seek serialize setflags uasort uksort unserialize valid',
		'recursivecachingiterator'               => 'count current getcache getchildren getflags getinneriterator haschildren hasnext key next offsetexists offsetget offsetset offsetunset rewind setflags valid',
		'recursivecallbackfilteriterator'        => 'accept current getchildren getinneriterator haschildren key next rewind valid',
		'recursivedirectoryiterator'             => '__debuginfo current getatime getbasename getchildren getctime getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize getsubpath getsubpathname gettype haschildren isdir isdot isexecutable isfile islink isreadable iswritable key next openfile rewind seek setfileclass setflags setinfoclass valid',
		'recursivefilteriterator'                => 'accept current getchildren getinneriterator haschildren key next rewind valid',
		'recursiveiterator'                      => 'current getchildren haschildren key next rewind valid',
		'recursiveiteratoriterator'              => 'beginchildren beginiteration callgetchildren callhaschildren current endchildren enditeration getdepth getinneriterator getmaxdepth getsubiterator key next nextelement rewind setmaxdepth valid',
		'recursiveregexiterator'                 => 'accept current getchildren getflags getinneriterator getmode getpregflags getregex haschildren key next rewind setflags setmode setpregflags valid',
		'recursivetreeiterator'                  => 'beginchildren beginiteration callgetchildren callhaschildren current endchildren enditeration getdepth getentry getinneriterator getmaxdepth getpostfix getprefix getsubiterator key next nextelement rewind setmaxdepth setpostfix setprefixpart valid',
		'reflection'                             => 'getmodifiernames',
		'reflectionclass'                        => 'getconstant getconstants getconstructor getdefaultproperties getdoccomment getendline getextension getextensionname getfilename getinterfacenames getinterfaces getmethod getmethods getmodifiers getname getnamespacename getparentclass getproperties getproperty getreflectionconstant getreflectionconstants getshortname getstartline getstaticproperties getstaticpropertyvalue gettraitaliases gettraitnames gettraits hasconstant hasmethod hasproperty implementsinterface innamespace isabstract isanonymous iscloneable isfinal isinstance isinstantiable isinterface isinternal isiterable isiterateable issubclassof istrait isuserdefined newinstance newinstanceargs newinstancewithoutconstructor setstaticpropertyvalue',
		'reflectionclassconstant'                => 'getdeclaringclass getdoccomment getmodifiers getname getvalue isprivate isprotected ispublic',
		'reflectionenum'                         => 'getconstant getconstants getconstructor getdefaultproperties getdoccomment getendline getextension getextensionname getfilename getinterfacenames getinterfaces getmethod getmethods getmodifiers getname getnamespacename getparentclass getproperties getproperty getreflectionconstant getreflectionconstants getshortname getstartline getstaticproperties getstaticpropertyvalue gettraitaliases gettraitnames gettraits hasconstant hasmethod hasproperty implementsinterface innamespace isabstract isanonymous iscloneable isfinal isinstance isinstantiable isinterface isinternal isiterable isiterateable issubclassof istrait isuserdefined newinstance newinstanceargs newinstancewithoutconstructor setstaticpropertyvalue',
		'reflectionenumbackedcase'               => 'getdeclaringclass getdoccomment getmodifiers getname isprivate isprotected ispublic',
		'reflectionenumunitcase'                 => 'getdeclaringclass getdoccomment getmodifiers getname isprivate isprotected ispublic',
		'reflectionexception'                    => '__wakeup',
		'reflectionextension'                    => 'getclasses getclassnames getconstants getdependencies getfunctions getinientries getname getversion info ispersistent istemporary',
		'reflectionfunction'                     => 'getclosure getclosurecalledclass getclosurescopeclass getclosurethis getdoccomment getendline getextension getextensionname getfilename getname getnamespacename getnumberofparameters getnumberofrequiredparameters getparameters getreturntype getshortname getstartline getstaticvariables hasreturntype innamespace invoke invokeargs isclosure isdeprecated isdisabled isgenerator isinternal isstatic isuserdefined isvariadic returnsreference',
		'reflectionfunctionabstract'             => 'getclosurecalledclass getclosurescopeclass getclosurethis getdoccomment getendline getextension getextensionname getfilename getname getnamespacename getnumberofparameters getnumberofrequiredparameters getparameters getreturntype getshortname getstartline getstaticvariables hasreturntype innamespace isclosure isdeprecated isgenerator isinternal isstatic isuserdefined isvariadic returnsreference',
		'reflectionintersectiontype'             => 'allowsnull',
		'reflectionmethod'                       => 'getclosure getclosurecalledclass getclosurescopeclass getclosurethis getdeclaringclass getdoccomment getendline getextension getextensionname getfilename getmodifiers getname getnamespacename getnumberofparameters getnumberofrequiredparameters getparameters getprototype getreturntype getshortname getstartline getstaticvariables hasreturntype innamespace invoke invokeargs isabstract isclosure isconstructor isdeprecated isdestructor isfinal isgenerator isinternal isprivate isprotected ispublic isstatic isuserdefined isvariadic returnsreference setaccessible',
		'reflectionnamedtype'                    => 'allowsnull getname isbuiltin',
		'reflectionobject'                       => 'getconstant getconstants getconstructor getdefaultproperties getdoccomment getendline getextension getextensionname getfilename getinterfacenames getinterfaces getmethod getmethods getmodifiers getname getnamespacename getparentclass getproperties getproperty getreflectionconstant getreflectionconstants getshortname getstartline getstaticproperties getstaticpropertyvalue gettraitaliases gettraitnames gettraits hasconstant hasmethod hasproperty implementsinterface innamespace isabstract isanonymous iscloneable isfinal isinstance isinstantiable isinterface isinternal isiterable isiterateable issubclassof istrait isuserdefined newinstance newinstanceargs newinstancewithoutconstructor setstaticpropertyvalue',
		'reflectionparameter'                    => 'allowsnull canbepassedbyvalue getclass getdeclaringclass getdeclaringfunction getdefaultvalue getdefaultvalueconstantname getname getposition gettype hastype isarray iscallable isdefaultvalueavailable isdefaultvalueconstant isoptional ispassedbyreference isvariadic',
		'reflectionproperty'                     => 'getdeclaringclass getdefaultvalue getdoccomment getmodifiers getname gettype getvalue hastype isdefault isinitialized isprivate isprotected ispublic isstatic setaccessible setvalue',
		'reflectiontype'                         => 'allowsnull',
		'reflectionuniontype'                    => 'allowsnull',
		'reflectionzendextension'                => 'getauthor getcopyright getname geturl getversion',
		'regexiterator'                          => 'accept current getflags getinneriterator getmode getpregflags getregex key next rewind setflags setmode setpregflags valid',
		'runtimeexception'                       => '__wakeup',
		'seekableiterator'                       => 'current key next rewind seek valid',
		'sessionhandler'                         => 'close create_sid destroy gc open read write',
		'sessionhandlerinterface'                => 'close destroy gc open read write',
		'sessionidinterface'                     => 'create_sid',
		'sessionupdatetimestamphandlerinterface' => 'updatetimestamp validateid',
		'simplexmlelement'                       => 'addattribute addchild asxml attributes children count current getchildren getdocnamespaces getname getnamespaces haschildren key next registerxpathnamespace rewind savexml valid xpath',
		'simplexmliterator'                      => 'addattribute addchild asxml attributes children count current getchildren getdocnamespaces getname getnamespaces haschildren key next registerxpathnamespace rewind savexml valid xpath',
		'soapclient'                             => '__call __dorequest __getcookies __getfunctions __getlastrequest __getlastrequestheaders __getlastresponse __getlastresponseheaders __gettypes __setcookie __setlocation __setsoapheaders __soapcall',
		'soapfault'                              => '__wakeup',
		'soapserver'                             => 'addfunction addsoapheader fault getfunctions handle setclass setobject setpersistence',
		'spldoublylinkedlist'                    => '__debuginfo __serialize __unserialize add bottom count current getiteratormode isempty key next offsetexists offsetget offsetset offsetunset pop prev push rewind serialize setiteratormode shift top unserialize unshift valid',
		'splfileinfo'                            => '__debuginfo getatime getbasename getctime getextension getfileinfo getfilename getgroup getinode getlinktarget getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype isdir isexecutable isfile islink isreadable iswritable openfile setfileclass setinfoclass',
		'splfileobject'                          => '__debuginfo current eof fflush fgetc fgetcsv fgets flock fpassthru fputcsv fread fscanf fseek fstat ftell ftruncate fwrite getatime getbasename getchildren getcsvcontrol getctime getcurrentline getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmaxlinelen getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype haschildren isdir isexecutable isfile islink isreadable iswritable key next openfile rewind seek setcsvcontrol setfileclass setflags setinfoclass setmaxlinelen valid',
		'splfixedarray'                          => '__wakeup count fromarray getsize offsetexists offsetget offsetset offsetunset toarray',
		'splheap'                                => '__debuginfo compare count current extract insert iscorrupted isempty key next recoverfromcorruption rewind top valid',
		'splmaxheap'                             => '__debuginfo compare count current extract insert iscorrupted isempty key next recoverfromcorruption rewind top valid',
		'splminheap'                             => '__debuginfo compare count current extract insert iscorrupted isempty key next recoverfromcorruption rewind top valid',
		'splobjectstorage'                       => '__debuginfo __serialize __unserialize addall attach contains count current detach gethash getinfo key next offsetexists offsetget offsetset offsetunset removeall removeallexcept rewind serialize setinfo unserialize valid',
		'splobserver'                            => 'update',
		'splpriorityqueue'                       => '__debuginfo compare count current extract getextractflags iscorrupted isempty key next rewind setextractflags top valid',
		'splqueue'                               => '__debuginfo __serialize __unserialize add bottom count current dequeue enqueue getiteratormode isempty key next offsetexists offsetget offsetset offsetunset pop prev push rewind serialize setiteratormode shift top unserialize unshift valid',
		'splstack'                               => '__debuginfo __serialize __unserialize add bottom count current getiteratormode isempty key next offsetexists offsetget offsetset offsetunset pop prev push rewind serialize setiteratormode shift top unserialize unshift valid',
		'splsubject'                             => 'attach detach notify',
		'spltempfileobject'                      => '__debuginfo current eof fflush fgetc fgetcsv fgets flock fpassthru fputcsv fread fscanf fseek fstat ftell ftruncate fwrite getatime getbasename getchildren getcsvcontrol getctime getcurrentline getextension getfileinfo getfilename getflags getgroup getinode getlinktarget getmaxlinelen getmtime getowner getpath getpathinfo getpathname getperms getrealpath getsize gettype haschildren isdir isexecutable isfile islink isreadable iswritable key next openfile rewind seek setcsvcontrol setfileclass setflags setinfoclass setmaxlinelen valid',
		'sqlite3'                                => 'backup busytimeout changes createaggregate createcollation createfunction enableexceptions enableextendedresultcodes escapestring exec lasterrorcode lasterrormsg lastextendederrorcode lastinsertrowid loadextension open prepare query querysingle setauthorizer version',
		'sqlite3result'                          => 'columnname columntype fetcharray numcolumns reset',
		'sqlite3stmt'                            => 'bindparam bindvalue clear close execute getsql paramcount readonly reset',
		'typeerror'                              => '__wakeup',
		'underflowexception'                     => '__wakeup',
		'unexpectedvalueexception'               => '__wakeup',
		'unhandledmatcherror'                    => '__wakeup',
		'valueerror'                             => '__wakeup',
		'xmlreader'                              => 'expand getattribute getattributeno getattributens getparserproperty isvalid lookupnamespace movetoattribute movetoattributeno movetoattributens movetoelement movetofirstattribute movetonextattribute next read readinnerxml readouterxml readstring setparserproperty setrelaxngschema setrelaxngschemasource setschema',
		'xmlwriter'                              => 'endattribute endcdata endcomment enddocument enddtd enddtdattlist enddtdelement enddtdentity endelement endpi flush fullendelement openmemory openuri outputmemory setindent setindentstring startattribute startattributens startcdata startcomment startdocument startdtd startdtdattlist startdtdelement startdtdentity startelement startelementns startpi text writeattribute writeattributens writecdata writecomment writedtd writedtdattlist writedtdelement writedtdentity writeelement writeelementns writepi writeraw',
		'ziparchive'                             => 'addemptydir addfile addfromstring addglob addpattern close count deleteindex deletename extractto getarchivecomment getcommentindex getcommentname getexternalattributesindex getexternalattributesname getfromindex getfromname getnameindex getstatusstring locatename open registercancelcallback registerprogresscallback renameindex renamename replacefile setarchivecomment setcommentindex setcommentname setcompressionindex setcompressionname setencryptionindex setencryptionname setexternalattributesindex setexternalattributesname setmtimeindex setmtimename setpassword statindex statname unchangeall unchangearchive unchangeindex unchangename',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Class_::class, Node\Stmt\Interface_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\Class_|Node\Stmt\Interface_ $node    Class or interface.
	 * @param Context                               $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$parents = array();

		if ( $node instanceof Node\Stmt\Class_ ) {
			if ( $node->extends instanceof Node\Name ) {
				$parents[] = $node->extends;
			}

			$parents = array_merge( $parents, $node->implements );
		} else {
			$parents = $node->extends;
		}

		$internal = array();

		foreach ( $parents as $parent ) {
			$resolved = $parent->getAttribute( 'resolvedName' );
			$resolved = $resolved instanceof Node\Name ? $resolved : $parent;
			$key      = strtolower( ltrim( $resolved->toString(), '\\' ) );

			if ( isset( self::METHODS[ $key ] ) ) {
				$internal[ $key ] = $resolved->toString();
			}
		}

		if ( ! $internal ) {
			return;
		}

		foreach ( $node->getMethods() as $method ) {
			if ( null !== $method->returnType || self::will_change( $method ) ) {
				continue;
			}

			$name = strtolower( $method->name->toString() );

			foreach ( $internal as $key => $display ) {
				if ( false === strpos( ' ' . self::METHODS[ $key ] . ' ', ' ' . $name . ' ' ) ) {
					continue;
				}

				// mysqli_stmt::execute() without parameters is a fatal error from
				// 8.1, reported as class.mysqli_stmt_execute_signature.
				if ( 'mysqli_stmt' === $key && 'execute' === $name && ! $method->params ) {
					break;
				}

				$this->report( $node, $method, $display, $context );
				break;
			}
		}
	}

	/**
	 * Records one finding with its fix.
	 *
	 * @param Node                  $class   Class or interface.
	 * @param Node\Stmt\ClassMethod $method  Method without a return type.
	 * @param string                $parent  Internal class or interface as written.
	 * @param Context               $context Scan state.
	 */
	private function report( Node $class, Node\Stmt\ClassMethod $method, $parent, Context $context ) {
		$finding          = $context->report( 'signature.tentative_return_type_missing', $method, array( 'deprecated' => '8.1' ) );
		$finding->subject = ltrim( $parent, '\\' ) . '::' . $method->name->toString() . '()';
		$finding->instead = '#[\ReturnTypeWillChange] above the method, or the return type that ' . $finding->subject . ' declares on PHP 8.1';

		// A class at the top of a file is declared when the file is compiled.
		// One inside an "if", a function or a "new class" is declared only when
		// that code runs.
		$around = $class->getAttribute( 'parent' );

		if ( null !== $around && ! $around instanceof Node\Stmt\Namespace_ ) {
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}

		$start  = $method->getStartFilePos();
		$line   = strrpos( substr( $context->code, 0, $start ), "\n" );
		$line   = false === $line ? 0 : $line + 1;
		$indent = substr( $context->code, $line, $start - $line );

		if ( '' !== trim( $indent, " \t" ) ) {
			$indent = '';
		}

		$finding->fix = array(
			'kind' => 'insert',
			'at'   => $start,
			'text' => "#[\\ReturnTypeWillChange]\n" . $indent,
		);
	}

	/**
	 * Whether the method carries #[ReturnTypeWillChange].
	 *
	 * @param Node\Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function will_change( Node\Stmt\ClassMethod $method ) {
		foreach ( $method->attrGroups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				if ( 'returntypewillchange' === strtolower( $attribute->name->getLast() ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
