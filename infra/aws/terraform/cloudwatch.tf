resource "aws_cloudwatch_log_group" "rds" {
  for_each = toset(var.rds_managed_cloudwatch_log_groups)

  name              = "/aws/rds/instance/${local.rds_identifier}/${each.value}"
  retention_in_days = var.cloudwatch_log_retention_days

  tags = {
    Name = "${local.name_prefix}-rds-${each.value}"
  }

  lifecycle {
    ignore_changes = [
      tags,
      tags_all,
    ]
  }
}

resource "aws_ssm_parameter" "cloudwatch_agent_config" {
  name = "AmazonCloudWatch-${local.name_prefix}-app"
  type = "String"

  value = jsonencode({
    agent = {
      metrics_collection_interval = 60
      region                      = var.aws_region
    }

    metrics = {
      namespace = "CWAgent"

      append_dimensions = {
        InstanceId = "$${aws:InstanceId}"
      }

      metrics_collected = {
        disk = {
          measurement                 = ["used_percent"]
          metrics_collection_interval = 60
          resources                   = ["/"]
          drop_device                 = true
        }
      }
    }
  })

  tags = {
    Name = "${local.name_prefix}-cloudwatch-agent"
  }
}

resource "aws_ssm_association" "cloudwatch_agent_install" {
  name             = "AWS-ConfigureAWSPackage"
  association_name = "${local.name_prefix}-cloudwatch-agent-install"

  parameters = {
    action  = "Install"
    name    = "AmazonCloudWatchAgent"
    version = "latest"
  }

  targets {
    key    = "InstanceIds"
    values = [aws_instance.app.id]
  }

  wait_for_success_timeout_seconds = 600

  depends_on = [
    aws_iam_role_policy_attachment.cloudwatch_agent_server,
  ]
}

resource "aws_ssm_association" "cloudwatch_agent_configure" {
  name             = "AmazonCloudWatch-ManageAgent"
  association_name = "${local.name_prefix}-cloudwatch-agent-configure"

  parameters = {
    action                        = "configure"
    mode                          = "ec2"
    optionalConfigurationSource   = "ssm"
    optionalConfigurationLocation = aws_ssm_parameter.cloudwatch_agent_config.name
    optionalRestart               = "yes"
  }

  targets {
    key    = "InstanceIds"
    values = [aws_instance.app.id]
  }

  wait_for_success_timeout_seconds = 600

  depends_on = [
    aws_ssm_association.cloudwatch_agent_install,
    aws_iam_role_policy_attachment.cloudwatch_agent_server,
  ]
}

resource "aws_sns_topic" "production_alarms" {
  name = "${local.name_prefix}-production-alarms"

  tags = {
    Name = "${local.name_prefix}-production-alarms"
  }
}

resource "aws_sns_topic_subscription" "production_alarms_email" {
  count = trimspace(var.alarm_notification_email) != "" ? 1 : 0

  topic_arn = aws_sns_topic.production_alarms.arn
  protocol  = "email"
  endpoint  = trimspace(var.alarm_notification_email)
}

resource "aws_cloudwatch_metric_alarm" "ec2_status_check_failed" {
  alarm_name          = "${local.name_prefix}-ec2-status-check-failed"
  alarm_description   = "EC2 application host failed an AWS instance or system status check."
  namespace           = "AWS/EC2"
  metric_name         = "StatusCheckFailed"
  comparison_operator = "GreaterThanOrEqualToThreshold"
  threshold           = 1
  period              = 60
  evaluation_periods  = 2
  datapoints_to_alarm = 2
  statistic           = "Maximum"
  treat_missing_data  = "missing"

  dimensions = {
    InstanceId = aws_instance.app.id
  }

  alarm_actions = [aws_sns_topic.production_alarms.arn]
  ok_actions    = [aws_sns_topic.production_alarms.arn]
}

resource "aws_cloudwatch_metric_alarm" "ec2_root_disk_used" {
  alarm_name          = "${local.name_prefix}-ec2-root-disk-used"
  alarm_description   = "Root filesystem usage is at or above the production safety threshold."
  namespace           = "CWAgent"
  metric_name         = "disk_used_percent"
  comparison_operator = "GreaterThanOrEqualToThreshold"
  threshold           = var.ec2_root_disk_used_percent_alarm_threshold
  period              = 300
  evaluation_periods  = 2
  datapoints_to_alarm = 2
  statistic           = "Average"
  treat_missing_data  = "missing"

  dimensions = {
    InstanceId = aws_instance.app.id
    path       = "/"
    fstype     = "ext4"
  }

  alarm_actions = [aws_sns_topic.production_alarms.arn]
  ok_actions    = [aws_sns_topic.production_alarms.arn]

  depends_on = [
    aws_ssm_association.cloudwatch_agent_configure,
  ]
}

resource "aws_cloudwatch_metric_alarm" "rds_free_storage" {
  alarm_name          = "${local.name_prefix}-rds-free-storage-low"
  alarm_description   = "RDS free storage remained below the production safety threshold."
  namespace           = "AWS/RDS"
  metric_name         = "FreeStorageSpace"
  comparison_operator = "LessThanThreshold"
  threshold           = var.rds_free_storage_alarm_bytes
  period              = 300
  evaluation_periods  = 2
  datapoints_to_alarm = 2
  statistic           = "Minimum"
  treat_missing_data  = "missing"

  dimensions = {
    DBInstanceIdentifier = aws_db_instance.mysql.identifier
  }

  alarm_actions = [aws_sns_topic.production_alarms.arn]
  ok_actions    = [aws_sns_topic.production_alarms.arn]
}

resource "aws_cloudwatch_metric_alarm" "rds_cpu" {
  alarm_name          = "${local.name_prefix}-rds-cpu-high"
  alarm_description   = "RDS CPU remained above the production threshold."
  namespace           = "AWS/RDS"
  metric_name         = "CPUUtilization"
  comparison_operator = "GreaterThanThreshold"
  threshold           = var.rds_cpu_alarm_threshold_percent
  period              = 300
  evaluation_periods  = 3
  datapoints_to_alarm = 3
  statistic           = "Average"
  treat_missing_data  = "missing"

  dimensions = {
    DBInstanceIdentifier = aws_db_instance.mysql.identifier
  }

  alarm_actions = [aws_sns_topic.production_alarms.arn]
  ok_actions    = [aws_sns_topic.production_alarms.arn]
}

resource "aws_cloudwatch_metric_alarm" "rds_database_connections" {
  alarm_name          = "${local.name_prefix}-rds-connections-high"
  alarm_description   = "RDS database connections remained above 80 percent of the verified MySQL max_connections=60 limit."
  namespace           = "AWS/RDS"
  metric_name         = "DatabaseConnections"
  comparison_operator = "GreaterThanThreshold"
  threshold           = var.rds_database_connections_alarm_threshold
  period              = 300
  evaluation_periods  = 3
  datapoints_to_alarm = 3
  statistic           = "Average"
  treat_missing_data  = "missing"

  dimensions = {
    DBInstanceIdentifier = aws_db_instance.mysql.identifier
  }

  alarm_actions = [aws_sns_topic.production_alarms.arn]
  ok_actions    = [aws_sns_topic.production_alarms.arn]
}
