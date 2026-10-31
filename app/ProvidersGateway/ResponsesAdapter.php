<?php

namespace App\ProvidersGateway;

/** 标记Responses线协议，避免网关依赖某一种测试夹具实现。 */
interface ResponsesAdapter extends ProviderAdapter {}
