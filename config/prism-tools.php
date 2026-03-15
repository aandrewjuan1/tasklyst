<?php

return [
    'create_task' => App\Tools\TaskAssistant\CreateTaskTool::class,
    'update_task' => App\Tools\TaskAssistant\UpdateTaskTool::class,
    'delete_task' => App\Tools\TaskAssistant\DeleteTaskTool::class,
    'restore_task' => App\Tools\TaskAssistant\RestoreTaskTool::class,
    'force_delete_task' => App\Tools\TaskAssistant\ForceDeleteTaskTool::class,
    'list_tasks' => App\Tools\TaskAssistant\ListTasksTool::class,
    'create_event' => App\Tools\TaskAssistant\CreateEventTool::class,
    'update_event' => App\Tools\TaskAssistant\UpdateEventTool::class,
    'delete_event' => App\Tools\TaskAssistant\DeleteEventTool::class,
    'restore_event' => App\Tools\TaskAssistant\RestoreEventTool::class,
    'create_project' => App\Tools\TaskAssistant\CreateProjectTool::class,
    'update_project' => App\Tools\TaskAssistant\UpdateProjectTool::class,
    'delete_project' => App\Tools\TaskAssistant\DeleteProjectTool::class,
    'restore_project' => App\Tools\TaskAssistant\RestoreProjectTool::class,
    'create_tag' => App\Tools\TaskAssistant\CreateTagTool::class,
    'delete_tag' => App\Tools\TaskAssistant\DeleteTagTool::class,
    'create_comment' => App\Tools\TaskAssistant\CreateCommentTool::class,
    'update_comment' => App\Tools\TaskAssistant\UpdateCommentTool::class,
    'delete_comment' => App\Tools\TaskAssistant\DeleteCommentTool::class,
];
