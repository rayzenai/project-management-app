<script lang="ts">
    /**
     * The column header for a register of `TaskRow`s. It shares the
     * `.task-cols` template with the rows, so the labels sit exactly over the
     * cells they name, and sticks under the 48px top bar while you scroll.
     */
</script>

<div class="task-cols task-head">
    <span>Code</span>
    <span>Task</span>
    <span class="hidden xl:block">Category</span>
    <span class="hidden 2xl:block">Ministry</span>
    <span>Status</span>
    <span><span class="sr-only">Priority</span></span>
    <span class="text-right">Due</span>
    <span class="text-right">Done</span>
    <span class="text-right">People</span>
</div>
