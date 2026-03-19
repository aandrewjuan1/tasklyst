<?php

return [
    'create_task' => App\Tools\LLM\TaskAssistant\CreateTaskTool::class,
    'update_task' => App\Tools\LLM\TaskAssistant\UpdateTaskTool::class,
    'delete_task' => App\Tools\LLM\TaskAssistant\DeleteTaskTool::class,
    'restore_task' => App\Tools\LLM\TaskAssistant\RestoreTaskTool::class,
    'force_delete_task' => App\Tools\LLM\TaskAssistant\ForceDeleteTaskTool::class,
    'list_tasks' => App\Tools\LLM\TaskAssistant\ListTasksTool::class,
    'create_event' => App\Tools\LLM\TaskAssistant\CreateEventTool::class,
    'update_event' => App\Tools\LLM\TaskAssistant\UpdateEventTool::class,
    'delete_event' => App\Tools\LLM\TaskAssistant\DeleteEventTool::class,
    'restore_event' => App\Tools\LLM\TaskAssistant\RestoreEventTool::class,
    'create_project' => App\Tools\LLM\TaskAssistant\CreateProjectTool::class,
    'update_project' => App\Tools\LLM\TaskAssistant\UpdateProjectTool::class,
    'delete_project' => App\Tools\LLM\TaskAssistant\DeleteProjectTool::class,
    'restore_project' => App\Tools\LLM\TaskAssistant\RestoreProjectTool::class,
    'create_tag' => App\Tools\LLM\TaskAssistant\CreateTagTool::class,
    'delete_tag' => App\Tools\LLM\TaskAssistant\DeleteTagTool::class,
    'create_comment' => App\Tools\LLM\TaskAssistant\CreateCommentTool::class,
    'update_comment' => App\Tools\LLM\TaskAssistant\UpdateCommentTool::class,
    'delete_comment' => App\Tools\LLM\TaskAssistant\DeleteCommentTool::class,
];
